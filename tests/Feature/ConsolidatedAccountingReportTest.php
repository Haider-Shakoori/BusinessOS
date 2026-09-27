<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\User;
use App\Services\ConsolidatedAccountingReportService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConsolidatedAccountingReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    public function test_only_authorized_same_currency_businesses_are_combined_with_exact_totals(): void
    {
        $user = User::create([
            'name' => 'Group Owner',
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('password'),
        ]);
        $other = User::create([
            'name' => 'Other Owner',
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('password'),
        ]);

        $businessA = $this->businessFor($user, 'Business A');
        $businessB = $this->businessFor($user, 'Business B');
        $businessC = $this->businessFor($other, 'Foreign Business');

        $this->actingAs($user);
        session([config('business.context.session_key') => $businessA->id]);
        $this->app->forgetScopedInstances();

        $this->postActivity($businessA, 'A', '100.0000', '40.0000');
        $this->postActivity($businessB, 'B', '200.0000', '50.0000');
        $this->postActivity($businessC, 'C', '999.0000', '1.0000');

        $service = app(ConsolidatedAccountingReportService::class);
        $available = $service->availableBusinesses();

        $this->assertEqualsCanonicalizing([$businessA->id, $businessB->id], $available->pluck('id')->all());
        $this->assertFalse($available->contains('id', $businessC->id));

        $report = $service->report([$businessA->id, $businessB->id], '2026-01-01', '2026-12-31');

        $this->assertSame('AFN', $report['currency']);
        $this->assertSame('300.0000', $report['combined']['total_income']);
        $this->assertSame('90.0000', $report['combined']['total_expenses']);
        $this->assertSame('210.0000', $report['combined']['net_profit']);
        $this->assertSame('210.0000', $report['combined']['total_assets']);
        $this->assertSame('210.0000', $report['combined']['current_earnings']);
        $this->assertSame('0.0000', $report['combined']['difference']);
        $this->assertFalse($report['is_consolidated']);

        $this->expectException(ValidationException::class);
        $service->report([$businessA->id, $businessC->id], '2026-01-01', '2026-12-31');
    }

    public function test_mixed_base_currencies_are_rejected_before_combining(): void
    {
        $user = User::create([
            'name' => 'Currency Owner',
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('password'),
        ]);
        $businessA = $this->businessFor($user, 'AFN Business');
        $businessB = $this->businessFor($user, 'USD Business');

        Setting::create([
            'business_id' => $businessB->id,
            'group' => 'regional',
            'key' => 'currency',
            'value' => 'USD',
            'type' => 'string',
        ]);

        $this->actingAs($user);
        session([config('business.context.session_key') => $businessA->id]);
        $this->app->forgetScopedInstances();

        $this->expectException(ValidationException::class);
        app(ConsolidatedAccountingReportService::class)->report([$businessA->id, $businessB->id]);
    }

    private function businessFor(User $user, string $name): Business
    {
        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        return $business;
    }

    private function postActivity(Business $business, string $suffix, string $salesAmount, string $expenseAmount): void
    {
        $cash = new Account;
        $cash->forceFill(['business_id' => $business->id, 'code' => '1000-'.$suffix, 'name' => 'Cash '.$suffix, 'type' => 'asset', 'is_active' => true])->save();

        $sales = new Account;
        $sales->forceFill(['business_id' => $business->id, 'code' => '4000-'.$suffix, 'name' => 'Sales '.$suffix, 'type' => 'income', 'is_active' => true])->save();

        $expense = new Account;
        $expense->forceFill(['business_id' => $business->id, 'code' => '5000-'.$suffix, 'name' => 'Expense '.$suffix, 'type' => 'expense', 'is_active' => true])->save();

        $sale = new JournalEntry;
        $sale->forceFill([
            'business_id' => $business->id,
            'number' => 'SALE-'.$suffix,
            'entry_date' => '2026-03-01',
            'status' => 'posted',
        ])->save();
        $sale->lines()->create(['account_id' => $cash->id, 'debit' => $salesAmount, 'credit' => '0']);
        $sale->lines()->create(['account_id' => $sales->id, 'debit' => '0', 'credit' => $salesAmount]);

        $cost = new JournalEntry;
        $cost->forceFill([
            'business_id' => $business->id,
            'number' => 'COST-'.$suffix,
            'entry_date' => '2026-04-01',
            'status' => 'posted',
        ])->save();
        $cost->lines()->create(['account_id' => $expense->id, 'debit' => $expenseAmount, 'credit' => '0']);
        $cost->lines()->create(['account_id' => $cash->id, 'debit' => '0', 'credit' => $expenseAmount]);
    }
}
