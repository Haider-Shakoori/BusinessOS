<?php

namespace Tests\Feature;

use App\Models\AccountingPosting;
use App\Models\AssetCategory;
use App\Models\AssetDepreciationEntry;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\CostCenter;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\FiscalPeriodService;
use App\Services\FixedAssetService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FixedAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    private function user(string $name = 'Asset Owner'): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function business(User $user, string $name = 'Asset Co'): Business
    {
        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (['dashboard', 'settings', 'accounting'] as $module) {
            BusinessModule::updateOrCreate(
                ['business_id' => $business->id, 'module_key' => $module],
                ['enabled' => true],
            );
        }

        return $business;
    }

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();
    }

    private function category(): AssetCategory
    {
        return AssetCategory::create([
            'code' => 'VEH',
            'name' => 'Vehicles',
            'depreciation_method' => 'straight_line',
            'useful_life_months' => 60,
            'salvage_percent' => '0',
            'asset_account_code' => 'AUTO-VEHICLE',
            'accumulated_depreciation_account_code' => 'AUTO-VEH-ACCDEP',
            'depreciation_expense_account_code' => 'AUTO-VEH-DEPEXP',
        ]);
    }

    private function assertBalanced(JournalEntry $entry): void
    {
        $entry->loadMissing('lines');

        $this->assertEqualsWithDelta(
            (float) $entry->lines->sum('debit'),
            (float) $entry->lines->sum('credit'),
            0.0001,
        );
    }

    public function test_fixed_asset_schema_permissions_and_workspace_exist(): void
    {
        foreach (['asset_categories', 'fixed_assets', 'asset_depreciation_entries'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertContains('assets.view', config('permissions.groups.assets'));
        $this->assertContains('assets.manage', config('permissions.groups.assets'));

        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $this->get('/accounting/assets')
            ->assertOk()
            ->assertSee(__('fixed_assets.title'));
    }

    public function test_acquisition_posts_balanced_asset_journal_with_cost_center(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $category = $this->category();
        $costCenter = CostCenter::create([
            'code' => 'OPS',
            'name' => 'Operations',
            'is_active' => true,
        ]);

        $asset = app(FixedAssetService::class)->acquire([
            'asset_category_id' => $category->id,
            'cost_center_id' => $costCenter->id,
            'asset_number' => 'FA-0001',
            'name' => 'Delivery Van',
            'acquisition_date' => '2026-01-01',
            'in_service_date' => '2026-01-01',
            'acquisition_cost' => '12000.0000',
            'salvage_value' => '0',
            'useful_life_months' => 60,
            'depreciation_method' => 'straight_line',
            'payment_method' => 'cash',
        ]);

        $this->assertSame('12000.0000', $asset->book_value);

        $posting = AccountingPosting::query()
            ->where('source_type', FixedAsset::class)
            ->where('source_id', $asset->id)
            ->where('event_key', 'acquisition')
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertBalanced($posting->journalEntry);
        $this->assertSame('12000.0000', $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-VEHICLE')->debit);
        $this->assertSame('12000.0000', $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-CASH')->credit);
        $this->assertSame($costCenter->id, $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-VEHICLE')->cost_center_id);
    }

    public function test_straight_line_depreciation_posts_monthly_and_is_idempotent(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $category = $this->category();
        $asset = app(FixedAssetService::class)->acquire([
            'asset_category_id' => $category->id,
            'asset_number' => 'FA-0002',
            'name' => 'Office Equipment',
            'acquisition_date' => '2026-01-01',
            'in_service_date' => '2026-01-01',
            'acquisition_cost' => '1200.0000',
            'salvage_value' => '0',
            'useful_life_months' => 12,
            'depreciation_method' => 'straight_line',
            'payment_method' => 'bank_transfer',
        ]);

        $updated = app(FixedAssetService::class)->depreciateThrough($asset, '2026-03-31');

        $this->assertSame('300.0000', $updated->accumulated_depreciation);
        $this->assertSame('900.0000', $updated->book_value);
        $this->assertSame(3, AssetDepreciationEntry::where('fixed_asset_id', $asset->id)->count());

        app(FixedAssetService::class)->depreciateThrough($updated, '2026-03-31');
        $this->assertSame(3, AssetDepreciationEntry::where('fixed_asset_id', $asset->id)->count());

        foreach (AssetDepreciationEntry::where('fixed_asset_id', $asset->id)->with('journalEntry.lines')->get() as $entry) {
            $this->assertBalanced($entry->journalEntry);
        }
    }

    public function test_depreciation_respects_salvage_floor(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $category = $this->category();
        $asset = app(FixedAssetService::class)->acquire([
            'asset_category_id' => $category->id,
            'asset_number' => 'FA-0003',
            'name' => 'Machine',
            'acquisition_date' => '2026-01-01',
            'in_service_date' => '2026-01-01',
            'acquisition_cost' => '1000.0000',
            'salvage_value' => '100.0000',
            'useful_life_months' => 3,
            'depreciation_method' => 'straight_line',
            'payment_method' => 'cash',
        ]);

        $updated = app(FixedAssetService::class)->depreciateThrough($asset, '2026-12-31');

        $this->assertSame('900.0000', $updated->accumulated_depreciation);
        $this->assertSame('100.0000', $updated->book_value);
        $this->assertSame(3, AssetDepreciationEntry::where('fixed_asset_id', $asset->id)->count());
    }

    public function test_disposal_posts_gain_or_loss_and_marks_asset_disposed(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $category = $this->category();
        $asset = app(FixedAssetService::class)->acquire([
            'asset_category_id' => $category->id,
            'asset_number' => 'FA-0004',
            'name' => 'Computer',
            'acquisition_date' => '2026-01-01',
            'in_service_date' => '2026-01-01',
            'acquisition_cost' => '1200.0000',
            'salvage_value' => '0',
            'useful_life_months' => 12,
            'depreciation_method' => 'straight_line',
            'payment_method' => 'cash',
        ]);

        app(FixedAssetService::class)->depreciateThrough($asset, '2026-03-31');
        $disposed = app(FixedAssetService::class)->dispose($asset->fresh(), '2026-04-15', '800.0000', 'Sold');

        $this->assertSame('disposed', $disposed->status);
        $this->assertSame('800.0000', $disposed->disposal_proceeds);

        $posting = AccountingPosting::query()
            ->where('source_type', FixedAsset::class)
            ->where('source_id', $asset->id)
            ->where('event_key', 'disposal')
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertBalanced($posting->journalEntry);
        $this->assertNotNull($posting->journalEntry->lines->firstWhere('account.code', 'AUTO-ASSET-LOSS'));
    }

    public function test_closed_fiscal_period_blocks_asset_acquisition_and_depreciation(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $period = app(FiscalPeriodService::class)->create('January', '2026-01-01', '2026-01-31');
        app(FiscalPeriodService::class)->close($period, $user->id, 'Closed');

        $category = $this->category();

        $this->expectException(ValidationException::class);

        app(FixedAssetService::class)->acquire([
            'asset_category_id' => $category->id,
            'asset_number' => 'FA-CLOSED',
            'name' => 'Blocked Asset',
            'acquisition_date' => '2026-01-15',
            'in_service_date' => '2026-01-15',
            'acquisition_cost' => '1000.0000',
            'salvage_value' => '0',
            'useful_life_months' => 12,
            'depreciation_method' => 'straight_line',
            'payment_method' => 'cash',
        ]);
    }

    public function test_cross_business_asset_route_is_not_visible(): void
    {
        $userA = $this->user('Owner A');
        $businessA = $this->business($userA, 'A Co');
        $this->actIn($userA, $businessA);
        $category = $this->category();
        $asset = app(FixedAssetService::class)->acquire([
            'asset_category_id' => $category->id,
            'asset_number' => 'A-1',
            'name' => 'A Asset',
            'acquisition_date' => '2026-02-01',
            'in_service_date' => '2026-02-01',
            'acquisition_cost' => '100.0000',
            'salvage_value' => '0',
            'useful_life_months' => 12,
            'depreciation_method' => 'straight_line',
            'payment_method' => 'cash',
        ]);

        $userB = $this->user('Owner B');
        $businessB = $this->business($userB, 'B Co');
        $this->actIn($userB, $businessB);

        $this->get('/accounting/assets/'.$asset->id)->assertNotFound();
    }
}
