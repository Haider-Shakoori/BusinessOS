<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tax;
use App\Models\User;
use App\Services\BusinessSettings;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Tax CRUD + optional-tax feature gate (Batch 11).
 *
 * The tax feature is SURFACE-level only: enabling/disabling general.tax_enabled
 * never creates or destroys tax rows, and a tax row's existence never implies
 * the feature is on — the tax-enabled middleware decides access by the setting
 * alone. Cross-business isolation applies to definitions, to the toggle, and
 * to uniqueness.
 */
class TaxTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        // Fresh in-memory connection, never migrate:fresh or a developer database.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    private function makeUser(string $name = 'Tax User'): User
    {
        return User::create(['name' => $name, 'email' => Str::random(10).'@example.test', 'password' => Hash::make('password')]);
    }

    private function makeBusiness(string $name): Business
    {
        return Business::create(['name' => $name]);
    }

    /**
     * @return array{business: Business, membership: BusinessMembership, roles: array<string, Role>}
     */
    private function provision(User $user, string $businessName, string $roleSlug): array
    {
        $business = $this->makeBusiness($businessName);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles[$roleSlug]);

        return [$business, $membership, $roles];
    }

    private function enableProducts(Business $business): void
    {
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => 'products'],
            ['enabled' => true],
        );
    }

    /**
     * Enable the tax feature via the BusinessSettings service — the same
     * authoritative source the tax-enabled middleware checks. Call AFTER
     * actIn() so the current business resolves; with no business context the
     * write is a no-op by design.
     */
    private function enableTax(): void
    {
        $this->rebuildContext();
        app(BusinessSettings::class)->set('general.tax_enabled', '1');
    }

    /**
     * Explicitly turn the feature off again (for reversible-toggle assertions).
     */
    private function disableTax(): void
    {
        $this->rebuildContext();
        app(BusinessSettings::class)->set('general.tax_enabled', '0');
    }

    private function makeTax(Business $business, array $attributes = []): Tax
    {
        $tax = new Tax(array_merge(['name' => 'Tax '.Str::random(5), 'rate' => '10.0000'], $attributes));
        $tax->business_id = $business->id;
        $tax->save();

        return $tax;
    }

    private function sessionKey(): string
    {
        return config('business.context.session_key');
    }

    private function rebuildContext(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([$this->sessionKey() => $business->id]);
        $this->rebuildContext();
    }

    // --- Schema --------------------------------------------------------------

    public function test_tax_schema_is_in_place(): void
    {
        $this->assertTrue(Schema::hasTable('taxes'));

        foreach (['business_id', 'name', 'rate', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('taxes', $column), "Expected taxes.$column to exist.");
        }

        // Rate is DECIMAL(8,4) — never FLOAT/DOUBLE. SQLite exposes a NUMERIC
        // affinity (SQLiteGrammar::typeDecimal), the compatible form of DECIMAL;
        // the authoritative check is the migration's declared type + the runtime type.
        $migration = file_get_contents(base_path('database/migrations/2026_09_11_000012_create_taxes_table.php'));
        $this->assertStringContainsString("decimal('rate', 8, 4)", $migration);

        $column = collect(Schema::getColumns('taxes'))->firstWhere('name', 'rate');
        $this->assertNotNull($column, 'taxes.rate column should be discoverable.');
        $type = strtolower((string) $column['type']);
        $this->assertNotContains($type, ['float', 'double', 'real'], "Rate must never be a floating point type, got '$type'.");
        $this->assertSame('numeric', $type);
    }

    // --- Route guarding: auth + business -------------------------------------

    public function test_tax_routes_require_authentication_and_a_business(): void
    {
        $this->get('/taxes')->assertRedirect(route('login'));
        $this->post('/taxes', ['name' => 'Nope', 'rate' => '10'])->assertRedirect(route('login'));

        $user = $this->makeUser();
        $this->actingAs($user)->get('/taxes')->assertRedirect(route('business.create'));
    }

    // --- Tax feature toggle: middleware gate ----------------------------------

    public function test_tax_feature_toggle_blocks_when_disabled_and_allows_when_enabled(): void
    {
        $user = $this->makeUser('Tax Toggle Owner');
        [$business] = $this->provision($user, 'Toggle Tax Co.', 'owner');
        $this->enableProducts($business);

        $this->actIn($user, $business);
        $this->enableTax();

        $tax = $this->makeTax($business, ['name' => 'VAT', 'rate' => '5.0000']);

        // Enabled: index loads with the tax row present.
        $this->get('/taxes')->assertOk()->assertSee('VAT');
        $this->get(route('taxes.edit', $tax))->assertOk();

        // Turn the feature off: every tax route is refused, data untouched.
        $this->disableTax();
        $this->get('/taxes')->assertForbidden();
        $this->get(route('taxes.edit', $tax))->assertForbidden();
        $this->post('/taxes', ['name' => 'New', 'rate' => '1.0000'])->assertForbidden();
        $this->patch(route('taxes.update', $tax), ['name' => 'Changed', 'rate' => '2.0000'])->assertForbidden();
        $this->delete(route('taxes.destroy', $tax))->assertForbidden();

        $this->assertDatabaseHas('taxes', ['id' => $tax->id, 'business_id' => $business->id, 'deleted_at' => null]);

        // Re-enable restores access to the same row.
        $this->enableTax();
        $this->get('/taxes')->assertOk()->assertSee('VAT');
    }

    public function test_tax_routes_require_products_module_even_when_tax_feature_is_on(): void
    {
        $user = $this->makeUser('Tax Mod Owner');
        [$business] = $this->provision($user, 'Mod Tax Co.', 'owner');

        $this->actIn($user, $business);
        $this->enableTax();

        // Module disabled: 403 even with tax_enabled ON.
        $this->get('/taxes')->assertForbidden();

        // Module enabled: 200.
        $this->enableProducts($business);
        $this->get('/taxes')->assertOk();
    }

    // --- Permission boundaries ------------------------------------------------

    public function test_reads_require_taxes_view_and_writes_require_taxes_manage(): void
    {
        $user = $this->makeUser('No Tax Perm');
        [$business] = $this->provision($user, 'No Perm Tax Co.', 'viewer');
        $this->enableProducts($business);

        $this->actIn($user, $business);
        $this->enableTax();

        $this->assertFalse(Gate::allows('taxes.view'));
        $this->get('/taxes')->assertForbidden();
        $this->get(route('taxes.create'))->assertForbidden();
    }

    public function test_member_with_view_only_can_read_but_cannot_modify(): void
    {
        $user = $this->makeUser('Tax Viewer');
        [$business, , $roles] = $this->provision($user, 'Read Tax Co.', 'viewer');
        $this->enableProducts($business);

        $viewId = Permission::where('name', 'taxes.view')->value('id');
        $roles['viewer']->permissions()->syncWithoutDetaching([$viewId]);
        $this->rebuildContext();

        $this->actIn($user, $business);
        $this->enableTax();
        $this->assertTrue(Gate::allows('taxes.view'));
        $this->assertFalse(Gate::allows('taxes.manage'));

        $tax = $this->makeTax($business, ['name' => 'Readable Tax', 'rate' => '10.0000']);

        $this->get('/taxes')
            ->assertOk()
            ->assertSee('Readable Tax')
            ->assertSee(__('taxes.view_only'));

        // Every write path is denied before validation.
        $this->get(route('taxes.create'))->assertForbidden();
        $this->post('/taxes', ['name' => 'Nope', 'rate' => '10.0000'])->assertForbidden();
        $this->get(route('taxes.edit', $tax))->assertForbidden();
        $this->patch(route('taxes.update', $tax), ['name' => 'Nope', 'rate' => '10.0000'])->assertForbidden();
        $this->delete(route('taxes.destroy', $tax))->assertForbidden();

        $this->assertSame('Readable Tax', $tax->fresh()->name);
        $this->assertDatabaseCount('taxes', 1);
    }

    public function test_manage_holder_is_blocked_when_tax_feature_is_off(): void
    {
        $user = $this->makeUser('Tax Admin Off');
        [$business] = $this->provision($user, 'Admin Tax Off', 'admin');
        $this->enableProducts($business);
        // Note: do NOT enableTax().

        $this->actIn($user, $business);
        $this->assertTrue(Gate::allows('taxes.manage'));

        // Feature is off: manage permission is irrelevant; 403.
        $this->post('/taxes', ['name' => 'Blocked', 'rate' => '5.0000'])->assertForbidden();
        $this->assertDatabaseCount('taxes', 0);
    }

    // --- Owner/manager happy path --------------------------------------------

    public function test_owner_can_create_update_and_soft_delete_a_tax(): void
    {
        $user = $this->makeUser('Tax Owner');
        [$business] = $this->provision($user, 'Own Tax Co.', 'owner');
        $this->enableProducts($business);

        $this->actIn($user, $business);
        $this->enableTax();

        $this->post('/taxes', [
            'name' => 'VAT',
            'rate' => '5.2500',
        ])->assertRedirect(route('taxes.index'))->assertSessionHas('status', __('taxes.created'));

        $tax = Tax::where('name', 'VAT')->firstOrFail();
        $this->assertSame($business->id, $tax->business_id);
        $this->assertSame('5.2500', $tax->rate);

        $this->patch(route('taxes.update', $tax), [
            'name' => 'VAT Updated',
            'rate' => '7.5000',
        ])->assertRedirect(route('taxes.index'))->assertSessionHas('status', __('taxes.updated'));

        $this->assertSame('VAT Updated', $tax->fresh()->name);
        $this->assertSame('7.5000', $tax->fresh()->rate);

        $this->delete(route('taxes.destroy', $tax))
            ->assertRedirect(route('taxes.index'))
            ->assertSessionHas('status', __('taxes.deleted'));

        $this->assertSoftDeleted('taxes', ['id' => $tax->id]);
        $this->get('/taxes')->assertOk()->assertDontSee('VAT Updated');
    }

    public function test_admin_role_can_manage_taxes(): void
    {
        $user = $this->makeUser('Tax Admin');
        [$business] = $this->provision($user, 'Admin Tax Co.', 'admin');
        $this->enableProducts($business);

        $this->actIn($user, $business);
        $this->enableTax();
        $this->assertTrue(Gate::allows('taxes.manage'));

        $this->post('/taxes', ['name' => 'Managed Tax', 'rate' => '10.0000'])->assertRedirect();
        $this->assertDatabaseHas('taxes', ['business_id' => $business->id, 'name' => 'Managed Tax', 'rate' => '10.0000']);
    }

    // --- DECIMAL precision persistence ---------------------------------------

    public function test_rate_is_stored_as_exact_decimal(): void
    {
        $user = $this->makeUser('Tax Precision');
        [$business] = $this->provision($user, 'Prec Co.', 'owner');
        $this->enableProducts($business);

        $this->actIn($user, $business);
        $this->enableTax();

        $this->post('/taxes', ['name' => 'Custom', 'rate' => '5.1234'])->assertRedirect();
        $tax = Tax::where('name', 'Custom')->firstOrFail();
        $this->assertSame('5.1234', $tax->rate);
        $this->assertSame('5.1234', (string) $tax->rate);

        $tax->update(['rate' => '0.0001']);
        $this->assertSame('0.0001', $tax->fresh()->rate);
    }

    // --- Rate validation -----------------------------------------------------

    public function test_tax_validation_rejects_invalid_rates(): void
    {
        $user = $this->makeUser('Tax Valid');
        [$business] = $this->provision($user, 'Valid Tax Co.', 'owner');
        $this->enableProducts($business);

        $this->actIn($user, $business);
        $this->enableTax();

        // Negative rate.
        $this->post('/taxes', ['name' => 'Bad', 'rate' => '-1'])
            ->assertSessionHasErrors('rate');
        // Over 100%.
        $this->post('/taxes', ['name' => 'Bad', 'rate' => '100.0001'])
            ->assertSessionHasErrors('rate');
        // Too many decimals.
        $this->post('/taxes', ['name' => 'Bad', 'rate' => '5.12345'])
            ->assertSessionHasErrors('rate');
        // Non-numeric.
        $this->post('/taxes', ['name' => 'Bad', 'rate' => 'abc'])
            ->assertSessionHasErrors('rate');
        // Missing name.
        $this->post('/taxes', ['name' => '', 'rate' => '10'])
            ->assertSessionHasErrors('name');
        // Missing rate.
        $this->post('/taxes', ['name' => 'Ok'])
            ->assertSessionHasErrors('rate');

        $this->assertDatabaseCount('taxes', 0);
    }

    public function test_tax_name_rejects_too_long(): void
    {
        $user = $this->makeUser('Tax Name Long');
        [$business] = $this->provision($user, 'Long Tax Co.', 'owner');
        $this->enableProducts($business);

        $this->actIn($user, $business);
        $this->enableTax();

        $this->post('/taxes', ['name' => str_repeat('a', 101), 'rate' => '10.0000'])
            ->assertSessionHasErrors('name');
    }

    // --- Tenancy: forged identifiers are ignored -----------------------------

    public function test_create_ignores_forged_business_id(): void
    {
        $user = $this->makeUser('Tax Forger');
        [$business] = $this->provision($user, 'Forge Tax Co.', 'owner');
        $this->enableProducts($business);
        $other = $this->makeBusiness('Victim Tax Co.');

        $this->actIn($user, $business);
        $this->enableTax();

        $this->post('/taxes', [
            'name' => 'Legit Tax',
            'rate' => '5.0000',
            'business_id' => $other->id,
            'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseHas('taxes', ['name' => 'Legit Tax', 'business_id' => $business->id]);
        $this->assertDatabaseMissing('taxes', ['business_id' => $other->id]);
        $tax = Tax::where('name', 'Legit Tax')->firstOrFail();
        $this->assertArrayNotHasKey('status', $tax->getAttributes());
    }

    public function test_update_ignores_forged_business_id(): void
    {
        $user = $this->makeUser('Tax Up Forger');
        [$business] = $this->provision($user, 'Up Forge Tax Co.', 'owner');
        $this->enableProducts($business);
        $other = $this->makeBusiness('Other Victim Tax Co.');
        $tax = $this->makeTax($business, ['name' => 'Stable Tax', 'rate' => '5.0000']);

        $this->actIn($user, $business);
        $this->enableTax();

        $this->patch(route('taxes.update', $tax), [
            'name' => 'Stable Tax',
            'rate' => '5.0000',
            'business_id' => $other->id,
        ])->assertRedirect(route('taxes.index'));

        $this->assertSame($business->id, $tax->fresh()->business_id);
        $this->assertDatabaseMissing('taxes', ['business_id' => $other->id]);
    }

    // --- Isolation: index + search are tenant-scoped --------------------------

    public function test_index_and_search_show_only_current_business_taxes(): void
    {
        $user = $this->makeUser('Tax Multi');
        [$businessA] = $this->provision($user, 'Alpha Tax Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Tax Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $this->actIn($user, $businessA);
        $this->enableTax();

        $this->makeTax($businessA, ['name' => 'Alpha VAT', 'rate' => '5.0000']);
        $this->makeTax($businessA, ['name' => 'Alpha WHT', 'rate' => '10.0000']);
        $this->makeTax($businessB, ['name' => 'Beta VAT', 'rate' => '7.5000']);

        $this->get('/taxes')
            ->assertOk()
            ->assertSee('Alpha VAT')
            ->assertSee('Alpha WHT')
            ->assertDontSee('Beta VAT');

        $this->get('/taxes?search=VAT')
            ->assertOk()
            ->assertSee('Alpha VAT')
            ->assertDontSee('Beta VAT');
    }

    public function test_cross_business_edit_update_and_delete_are_blocked(): void
    {
        $user = $this->makeUser('Tax Cross');
        [$businessA] = $this->provision($user, 'Alpha Tax Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Tax Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $this->actIn($user, $businessA);
        $this->enableTax();

        $foreign = $this->makeTax($businessB, ['name' => 'Foreign Tax', 'rate' => '5.0000']);

        $this->get(route('taxes.edit', $foreign))->assertNotFound();
        $this->patch(route('taxes.update', $foreign), ['name' => 'Hijacked', 'rate' => '10.0000'])->assertNotFound();
        $this->delete(route('taxes.destroy', $foreign))->assertNotFound();

        $this->assertSame('Foreign Tax', $foreign->fresh()->name);
        $this->assertFalse($foreign->fresh()->trashed());
    }

    public function test_multi_business_switch_isolation(): void
    {
        $user = $this->makeUser('Tax Switch');
        [$businessA] = $this->provision($user, 'Alpha Tax Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Tax Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $this->actIn($user, $businessA);
        $this->enableTax(); // Alpha: tax ON.

        $alpha = $this->makeTax($businessA, ['name' => 'Sales Tax', 'rate' => '5.0000']);
        $beta = $this->makeTax($businessB, ['name' => 'Withholding Tax', 'rate' => '10.0000']);

        $this->get('/taxes')->assertOk()->assertSee('Sales Tax')->assertDontSee('Withholding Tax');
        $this->get(route('taxes.edit', $beta))->assertNotFound();

        $this->post(route('business.switch'), ['business_id' => $businessB->id])->assertRedirect(route('app.home'));

        $this->get('/taxes')->assertForbidden(); // Beta still has tax OFF.

        $this->enableTax(); // Beta: tax ON.
        $this->get('/taxes')->assertOk()->assertSee('Withholding Tax')->assertDontSee('Sales Tax');
        $this->get(route('taxes.edit', $beta))->assertOk();

        // A write targeting A's tax while acting in B must not leak.
        $this->patch(route('taxes.update', $alpha), ['name' => 'Hijacked', 'rate' => '10.0000'])->assertNotFound();
        $this->assertSame('Sales Tax', $alpha->fresh()->name);
    }

    // --- Business-scoped uniqueness (soft-delete aware) -----------------------

    public function test_tax_names_are_unique_within_business_but_not_across_businesses(): void
    {
        $user = $this->makeUser('Tax Unique');
        [$businessA] = $this->provision($user, 'Alpha Tax Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Tax Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $this->actIn($user, $businessA);
        $this->enableTax();
        $this->makeTax($businessA, ['name' => 'Shared Tax', 'rate' => '5.0000']);

        $this->post('/taxes', ['name' => 'Shared Tax', 'rate' => '10.0000'])
            ->assertSessionHasErrors('name');

        // Same name in a different business is allowed.
        $this->actIn($user, $businessB);
        $this->enableTax();
        $this->post('/taxes', ['name' => 'Shared Tax', 'rate' => '7.5000'])->assertRedirect();
        $this->assertDatabaseHas('taxes', ['business_id' => $businessB->id, 'name' => 'Shared Tax']);

        // Editing keeps the same name (ignore self).
        $this->patch(route('taxes.update', Tax::where('business_id', $businessB->id)->firstOrFail()), [
            'name' => 'Shared Tax',
            'rate' => '7.5000',
        ])->assertRedirect(route('taxes.index'));

        $this->assertDatabaseCount('taxes', 2);
    }

    public function test_soft_deleted_tax_does_not_block_recreating_the_same_name(): void
    {
        $user = $this->makeUser('Tax Recycle');
        [$business] = $this->provision($user, 'Recycle Tax Co.', 'owner');
        $this->enableProducts($business);

        $this->actIn($user, $business);
        $this->enableTax();

        $tax = $this->makeTax($business, ['name' => 'Reusable Tax', 'rate' => '5.0000']);
        $this->delete(route('taxes.destroy', $tax))->assertRedirect(route('taxes.index'));
        $this->assertSoftDeleted('taxes', ['id' => $tax->id]);

        $this->post('/taxes', ['name' => 'Reusable Tax', 'rate' => '10.0000'])->assertRedirect();
        $this->assertDatabaseCount('taxes', 2);
        $this->assertDatabaseHas('taxes', ['business_id' => $business->id, 'name' => 'Reusable Tax', 'deleted_at' => null]);
    }

    // --- Tax exists never implies feature enabled -----------------------------

    public function test_tax_exists_never_implies_feature_enabled(): void
    {
        $user = $this->makeUser('Tax Exists');
        [$business] = $this->provision($user, 'Exists Tax Co.', 'owner');
        $this->enableProducts($business);

        $this->actIn($user, $business);
        $this->enableTax();
        $tax = $this->makeTax($business, ['name' => 'Persistent VAT', 'rate' => '5.0000']);

        // Disable the feature: the tax row still exists, but the route is refused.
        $this->disableTax();
        $this->get('/taxes')->assertForbidden();
        $this->assertDatabaseHas('taxes', ['id' => $tax->id, 'deleted_at' => null]);

        // Re-enable: the same row is accessible again without duplication.
        $this->enableTax();
        $this->get('/taxes')->assertOk()->assertSee('Persistent VAT');
        $this->assertDatabaseCount('taxes', 1);
    }

    // --- Combined scenario: same user in A (tax on) + B (tax off) -------------

    public function test_same_user_two_businesses_independent_tax_toggle(): void
    {
        $user = $this->makeUser('Tax Multi Switch');
        [$businessA] = $this->provision($user, 'Alpha Tax Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Tax Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $this->actIn($user, $businessA);
        $this->enableTax(); // Alpha: tax ON.
        $this->makeTax($businessA, ['name' => 'Alpha VAT', 'rate' => '5.0000']);
        $this->get('/taxes')->assertOk()->assertSee('Alpha VAT');

        // Switch to Beta: tax disabled by default, list is refused.
        $this->post(route('business.switch'), ['business_id' => $businessB->id])->assertRedirect(route('app.home'));
        $this->get('/taxes')->assertForbidden();

        // Switch back to Alpha: tax still enabled, row still present.
        $this->post(route('business.switch'), ['business_id' => $businessA->id])->assertRedirect(route('app.home'));
        $this->get('/taxes')->assertOk()->assertSee('Alpha VAT');
    }

    // --- Re-enable preserves definitions (regression) -------------------------

    public function test_re_enabling_tax_feature_preserves_existing_definitions(): void
    {
        $user = $this->makeUser('Tax Persist');
        [$business] = $this->provision($user, 'Persist Tax Co.', 'owner');
        $this->enableProducts($business);

        $this->actIn($user, $business);
        $this->enableTax();

        $tax = $this->makeTax($business, ['name' => 'VAT 5%', 'rate' => '5.0000']);
        $taxId = $tax->id;

        $this->disableTax();
        $this->assertDatabaseHas('taxes', ['id' => $taxId, 'deleted_at' => null]);
        $this->assertNull($tax->fresh()->deleted_at);

        $this->enableTax();
        $this->assertSame($taxId, Tax::where('id', $taxId)->firstOrFail()->id);
        $this->assertSame('5.0000', Tax::find($taxId)->rate);
    }

    // --- Pagination with search preservation ---------------------------------

    public function test_pagination_preserves_search_query(): void
    {
        $user = $this->makeUser('Tax Pager');
        [$business] = $this->provision($user, 'Page Tax Co.', 'owner');
        $this->enableProducts($business);

        $this->actIn($user, $business);
        $this->enableTax();

        for ($i = 1; $i <= 20; $i++) {
            $this->makeTax($business, ['name' => sprintf('Alpha Tax %02d', $i), 'rate' => '1.0000']);
        }
        $this->makeTax($business, ['name' => 'Beta Tax', 'rate' => '2.0000']);

        $this->get('/taxes?search=Alpha')
            ->assertOk()
            ->assertSee('Alpha Tax 01')
            ->assertDontSee('Beta Tax')
            ->assertSee('search=Alpha', false);

        $this->get('/taxes?search=Alpha&page=2')
            ->assertOk()
            ->assertSee('Alpha Tax 16');
    }

    // --- Navigation points at the real route ----------------------------------

    public function test_tax_navigation_uses_the_real_route(): void
    {
        $children = config('modules.registry.products.navigation.children');
        $this->assertIsArray($children);
        $this->assertSame('taxes.index', $children[2]['route']);
        $this->assertSame('taxes.view', $children[2]['permission']);
        $this->assertSame('general.tax_enabled', $children[2]['setting']);

        $user = $this->makeUser('Tax Nav Owner');
        [$business] = $this->provision($user, 'Nav Tax Co.', 'owner');
        $this->enableProducts($business);

        $this->actIn($user, $business);
        $this->enableTax();

        $this->get('/taxes')
            ->assertOk()
            ->assertSee(route('taxes.index'), false);
    }
}
