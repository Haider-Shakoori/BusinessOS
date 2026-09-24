<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerTest extends TestCase
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

    private function makeUser(string $name = 'Customer User'): User
    {
        return User::create(['name' => $name, 'email' => Str::random(10).'@example.test', 'password' => Hash::make('password')]);
    }

    private function makeBusiness(string $name): Business
    {
        return Business::create(['name' => $name]);
    }

    /**
     * Provision a business with default roles + default modules and attach the
     * given user with the given default-role slug.
     *
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

    /**
     * The customers module is NOT enabled by default (config/modules.php), so
     * each test explicitly activates it to exercise the module guard.
     */
    private function enableCustomers(Business $business): void
    {
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => 'customers'],
            ['enabled' => true],
        );
    }

    /**
     * Create a customer fixture owned by the given business.
     *
     * business_id is never mass assignable (tenancy invariant), so it is set
     * explicitly here as a trusted internal path — exactly what a factory or
     * seeder would do.
     */
    private function makeCustomer(Business $business, array $attributes = []): Customer
    {
        $customer = new Customer(array_merge(['name' => 'Customer '.Str::random(5)], $attributes));
        $customer->business_id = $business->id;
        $customer->save();

        return $customer;
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

    public function test_customer_schema_is_in_place(): void
    {
        $this->assertTrue(Schema::hasTable('customers'));

        foreach (['business_id', 'name', 'company_name', 'email', 'phone', 'address', 'notes', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('customers', $column), "Expected customers.$column to exist.");
        }
    }

    // --- Route guarding: auth + business + module + permission ---------------

    public function test_customer_routes_require_authentication_and_a_business(): void
    {
        $this->get('/customers')->assertRedirect(route('login'));
        $this->post('/customers', ['name' => 'Nope'])->assertRedirect(route('login'));

        // Authenticated but with no business: onboarding state, never the list.
        $user = $this->makeUser();
        $this->actingAs($user)->get('/customers')->assertRedirect(route('business.create'));
    }

    public function test_customer_module_guard_blocks_when_disabled_and_keeps_data_intact(): void
    {
        $user = $this->makeUser('Mod Owner');
        [$business] = $this->provision($user, 'Mod Co.', 'owner');
        $this->enableCustomers($business);
        $customer = $this->makeCustomer($business, ['name' => 'Kept Customer']);

        $this->actIn($user, $business);
        $this->get('/customers')->assertOk()->assertSee('Kept Customer');

        // Disable the module: the same owner is refused outright, even by URL.
        $business->modules()->where('module_key', 'customers')->update(['enabled' => false]);
        $this->get('/customers')->assertForbidden();
        $this->get(route('customers.show', $customer))->assertForbidden();
        $this->post('/customers', ['name' => 'Blocked'])->assertForbidden();

        // Data is untouched by disabling the module.
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'business_id' => $business->id, 'deleted_at' => null]);

        // Re-enabling restores access for the owner.
        $business->modules()->where('module_key', 'customers')->update(['enabled' => true]);
        $this->get('/customers')->assertOk()->assertSee('Kept Customer');
    }

    public function test_reads_require_customers_view_and_writes_require_customers_manage(): void
    {
        $user = $this->makeUser('No Perm');
        [$business] = $this->provision($user, 'No Perm Co.', 'viewer');
        $this->enableCustomers($business);
        $customer = $this->makeCustomer($business, ['name' => 'Visible Co']);

        $this->actIn($user, $business);

        // The default viewer role holds customers.view (read) but not manage
        // (write) since Batch 18 made customer ledger reading part of viewer.
        $this->assertTrue(Gate::allows('customers.view'));
        $this->assertFalse(Gate::allows('customers.manage'));
        $this->get('/customers')->assertOk()->assertSee('Visible Co');
        $this->get(route('customers.show', $customer))->assertOk();
        $this->get(route('customers.create'))->assertForbidden();
    }

    // --- Mandatory authorization scenario: view without manage ----------------

    public function test_member_with_view_only_can_read_but_cannot_modify(): void
    {
        $user = $this->makeUser('Viewer');
        [$business, , $roles] = $this->provision($user, 'Read Co.', 'viewer');
        $this->enableCustomers($business);

        // Grant read-only access to the otherwise permission-less viewer role.
        $viewId = Permission::where('name', 'customers.view')->value('id');
        $roles['viewer']->permissions()->syncWithoutDetaching([$viewId]);
        $this->rebuildContext();

        $this->actIn($user, $business);
        $this->assertTrue(Gate::allows('customers.view'));
        $this->assertFalse(Gate::allows('customers.manage'));

        $customer = $this->makeCustomer($business, ['name' => 'Readable Co']);

        // Reads work and the read-only notice is shown.
        $this->get('/customers')
            ->assertOk()
            ->assertSee('Readable Co')
            ->assertSee(__('customers.view_only'));
        $this->get(route('customers.show', $customer))->assertOk();

        // Every write path is denied before validation.
        $this->get(route('customers.create'))->assertForbidden();
        $this->post('/customers', ['name' => 'Nope'])->assertForbidden();
        $this->get(route('customers.edit', $customer))->assertForbidden();
        $this->patch(route('customers.update', $customer), ['name' => 'Nope'])->assertForbidden();
        $this->delete(route('customers.destroy', $customer))->assertForbidden();

        $this->assertSame('Readable Co', $customer->fresh()->name);
        $this->assertDatabaseCount('customers', 1);
    }

    // --- Owner/manager happy path --------------------------------------------

    public function test_owner_can_create_view_update_and_soft_delete_a_customer(): void
    {
        $user = $this->makeUser('Owner');
        [$business] = $this->provision($user, 'Own Co.', 'owner');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $this->post('/customers', [
            'name' => 'Created Co',
            'company_name' => 'Created Holdings',
            'email' => 'hello@created.test',
            'phone' => '+1 555 0100',
            'address' => '1 Created Way',
            'notes' => 'A new customer.',
        ])->assertRedirect()->assertSessionHas('status', __('customers.created'));

        $customer = Customer::where('name', 'Created Co')->firstOrFail();
        $this->assertSame($business->id, $customer->business_id);
        $this->assertSame('Created Holdings', $customer->company_name);

        $this->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Created Co')
            ->assertSee('hello@created.test');

        $this->patch(route('customers.update', $customer), [
            'name' => 'Renamed Co',
            'email' => 'renamed@created.test',
        ])->assertRedirect(route('customers.show', $customer))->assertSessionHas('status', __('customers.updated'));

        $this->assertSame('Renamed Co', $customer->fresh()->name);
        $this->assertSame('renamed@created.test', $customer->fresh()->email);

        // Soft delete: the row remains, only deleted_at is set.
        $this->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('status', __('customers.deleted'));

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->get('/customers')->assertOk()->assertDontSee('Renamed Co');
        $this->get(route('customers.show', $customer))->assertNotFound();
    }

    public function test_admin_role_can_manage_customers(): void
    {
        $user = $this->makeUser('Admin');
        [$business] = $this->provision($user, 'Admin Co.', 'admin');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $this->assertTrue(Gate::allows('customers.manage'));

        $this->post('/customers', ['name' => 'Managed Co'])->assertRedirect();
        $this->assertDatabaseHas('customers', ['business_id' => $business->id, 'name' => 'Managed Co']);
    }

    // --- Tenancy: forged identifiers are ignored -----------------------------

    public function test_create_ignores_forged_business_id(): void
    {
        $user = $this->makeUser('Forger');
        [$business] = $this->provision($user, 'Forge Co.', 'owner');
        $this->enableCustomers($business);
        $other = $this->makeBusiness('Victim Co.');

        $this->actIn($user, $business);

        $this->post('/customers', [
            'name' => 'Legit Customer',
            'business_id' => $other->id,
            'status' => 'vip',
            'balance' => 999999,
        ])->assertRedirect();

        $this->assertDatabaseHas('customers', ['name' => 'Legit Customer', 'business_id' => $business->id]);
        $this->assertDatabaseMissing('customers', ['business_id' => $other->id]);
        // Unknown keys never persist (there is no `balance`/`status` column).
        $customer = Customer::where('name', 'Legit Customer')->firstOrFail();
        $this->assertArrayNotHasKey('balance', $customer->getAttributes());
        $this->assertArrayNotHasKey('status', $customer->getAttributes());
    }

    public function test_update_ignores_forged_business_id(): void
    {
        $user = $this->makeUser('Up Forger');
        [$business] = $this->provision($user, 'Up Forge Co.', 'owner');
        $this->enableCustomers($business);
        $other = $this->makeBusiness('Other Victim Co.');
        $customer = $this->makeCustomer($business, ['name' => 'Stable Customer']);

        $this->actIn($user, $business);

        $this->patch(route('customers.update', $customer), [
            'name' => 'Stable Customer',
            'business_id' => $other->id,
        ])->assertRedirect(route('customers.show', $customer));

        $this->assertSame($business->id, $customer->fresh()->business_id);
        $this->assertDatabaseMissing('customers', ['business_id' => $other->id]);
    }

    // --- Isolation: index + search are tenant-scoped --------------------------

    public function test_index_and_search_show_only_current_business_customers(): void
    {
        $user = $this->makeUser('Multi Owner');
        [$businessA] = $this->provision($user, 'Alpha Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Co.', 'owner');
        $this->enableCustomers($businessA);
        $this->enableCustomers($businessB);

        $this->makeCustomer($businessA, ['name' => 'Alpha Searchable']);
        $this->makeCustomer($businessA, ['name' => 'Alpha Other']);
        $this->makeCustomer($businessB, ['name' => 'Beta Searchable']);

        $this->actIn($user, $businessA);

        $this->get('/customers')
            ->assertOk()
            ->assertSee('Alpha Searchable')
            ->assertSee('Alpha Other')
            ->assertDontSee('Beta Searchable');

        // Search matches only the current business, even for an identical term.
        $this->get('/customers?search=Searchable')
            ->assertOk()
            ->assertSee('Alpha Searchable')
            ->assertDontSee('Beta Searchable');
    }

    public function test_cross_business_show_edit_and_update_are_blocked(): void
    {
        $user = $this->makeUser('Cross Owner');
        [$businessA] = $this->provision($user, 'Alpha Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Co.', 'owner');
        $this->enableCustomers($businessA);
        $this->enableCustomers($businessB);
        $foreign = $this->makeCustomer($businessB, ['name' => 'Foreign Customer']);

        $this->actIn($user, $businessA);

        $this->get(route('customers.show', $foreign))->assertNotFound();
        $this->get(route('customers.edit', $foreign))->assertNotFound();
        $this->patch(route('customers.update', $foreign), ['name' => 'Hijacked'])->assertNotFound();
        $this->delete(route('customers.destroy', $foreign))->assertNotFound();

        $this->assertSame('Foreign Customer', $foreign->fresh()->name);
        $this->assertFalse($foreign->fresh()->trashed());
    }

    public function test_multi_business_switch_isolation(): void
    {
        $user = $this->makeUser('Switch Owner');
        [$businessA] = $this->provision($user, 'Alpha Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Co.', 'owner');
        $this->enableCustomers($businessA);
        $this->enableCustomers($businessB);

        $alpha = $this->makeCustomer($businessA, ['name' => 'Alpha Customer']);
        $beta = $this->makeCustomer($businessB, ['name' => 'Beta Customer']);

        $this->actIn($user, $businessA);
        $this->get('/customers')->assertOk()->assertSee('Alpha Customer')->assertDontSee('Beta Customer');
        $this->get(route('customers.show', $alpha))->assertOk();
        $this->get(route('customers.show', $beta))->assertNotFound();

        // Switch to B via the real switch endpoint.
        $this->post(route('business.switch'), ['business_id' => $businessB->id])->assertRedirect(route('app.home'));

        $this->get('/customers')->assertOk()->assertSee('Beta Customer')->assertDontSee('Alpha Customer');
        $this->get(route('customers.show', $beta))->assertOk();
        $this->get(route('customers.show', $alpha))->assertNotFound();

        // A write targeting A's customer while acting in B must not leak.
        $this->patch(route('customers.update', $alpha), ['name' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Alpha Customer', $alpha->fresh()->name);
    }

    // --- Validation ----------------------------------------------------------

    public function test_customer_validation_rejects_invalid_input(): void
    {
        $user = $this->makeUser('Valid Owner');
        [$business] = $this->provision($user, 'Valid Co.', 'owner');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $this->post('/customers', ['name' => ''])
            ->assertSessionHasErrors(['name' => __('customers.validation.name_required')]);

        $this->post('/customers', ['name' => str_repeat('a', 101)])
            ->assertSessionHasErrors(['name' => __('customers.validation.name_max')]);

        $this->post('/customers', ['name' => 'Ok', 'company_name' => str_repeat('a', 101)])
            ->assertSessionHasErrors(['company_name' => __('customers.validation.company_name_max')]);

        $this->post('/customers', ['name' => 'Ok', 'email' => 'not-an-email'])
            ->assertSessionHasErrors(['email' => __('customers.validation.email_invalid')]);

        $this->post('/customers', ['name' => 'Ok', 'phone' => str_repeat('1', 31)])
            ->assertSessionHasErrors(['phone' => __('customers.validation.phone_max')]);

        $this->post('/customers', ['name' => 'Ok', 'address' => str_repeat('a', 501)])
            ->assertSessionHasErrors(['address' => __('customers.validation.address_max')]);

        $this->post('/customers', ['name' => 'Ok', 'notes' => str_repeat('a', 2001)])
            ->assertSessionHasErrors(['notes' => __('customers.validation.notes_max')]);

        $this->assertDatabaseCount('customers', 0);
    }

    // --- Pagination with search preservation ---------------------------------

    public function test_pagination_preserves_search_query(): void
    {
        $user = $this->makeUser('Pager');
        [$business] = $this->provision($user, 'Page Co.', 'owner');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        for ($i = 1; $i <= 20; $i++) {
            $this->makeCustomer($business, ['name' => sprintf('Alpha Customer %02d', $i)]);
        }
        $this->makeCustomer($business, ['name' => 'Beta Customer']);

        $this->get('/customers?search=Alpha')
            ->assertOk()
            ->assertSee('Alpha Customer 01')
            ->assertDontSee('Beta Customer')
            ->assertSee('search=Alpha', false);

        $this->get('/customers?search=Alpha&page=2')
            ->assertOk()
            ->assertSee('Alpha Customer 16');
    }

    // --- Navigation points at the real route ----------------------------------

    public function test_customer_navigation_uses_the_real_route(): void
    {
        $this->assertSame('customers.index', config('modules.registry.customers.navigation.route'));

        $user = $this->makeUser('Nav Owner');
        [$business] = $this->provision($user, 'Nav Co.', 'owner');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $this->get('/customers')
            ->assertOk()
            ->assertSee(route('customers.index'), false);
    }
}
