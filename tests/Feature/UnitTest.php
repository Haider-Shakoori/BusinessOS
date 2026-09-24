<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnitTest extends TestCase
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

    private function makeUser(string $name = 'Unit User'): User
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

    private function makeUnit(Business $business, array $attributes = []): Unit
    {
        $unit = new Unit(array_merge(['name' => 'Unit '.Str::random(5)], $attributes));
        $unit->business_id = $business->id;
        $unit->save();

        return $unit;
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

    public function test_unit_schema_is_in_place(): void
    {
        $this->assertTrue(Schema::hasTable('units'));

        foreach (['business_id', 'name', 'short_name', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('units', $column), "Expected units.$column to exist.");
        }
    }

    // --- Route guarding: auth + business + module + permission ---------------

    public function test_unit_routes_require_authentication_and_a_business(): void
    {
        $this->get('/units')->assertRedirect(route('login'));
        $this->post('/units', ['name' => 'Nope'])->assertRedirect(route('login'));

        $user = $this->makeUser();
        $this->actingAs($user)->get('/units')->assertRedirect(route('business.create'));
    }

    public function test_unit_module_guard_blocks_when_disabled_and_keeps_data_intact(): void
    {
        $user = $this->makeUser('Unit Owner');
        [$business] = $this->provision($user, 'Unit Co.', 'owner');
        $this->enableProducts($business);
        $unit = $this->makeUnit($business, ['name' => 'Kept Unit']);

        $this->actIn($user, $business);
        $this->get('/units')->assertOk()->assertSee('Kept Unit');

        $business->modules()->where('module_key', 'products')->update(['enabled' => false]);
        $this->get('/units')->assertForbidden();
        $this->post('/units', ['name' => 'Blocked'])->assertForbidden();

        $this->assertDatabaseHas('units', ['id' => $unit->id, 'business_id' => $business->id, 'deleted_at' => null]);

        $business->modules()->where('module_key', 'products')->update(['enabled' => true]);
        $this->get('/units')->assertOk()->assertSee('Kept Unit');
    }

    public function test_reads_require_units_view_and_writes_require_units_manage(): void
    {
        $user = $this->makeUser('No Perm Unit');
        [$business] = $this->provision($user, 'No Perm Unit Co.', 'viewer');
        $this->enableProducts($business);

        $this->actIn($user, $business);

        $this->assertFalse(Gate::allows('units.view'));
        $this->get('/units')->assertForbidden();
        $this->get(route('units.create'))->assertForbidden();
    }

    // --- Mandatory authorization scenario: view without manage ----------------

    public function test_member_with_view_only_can_read_but_cannot_modify(): void
    {
        $user = $this->makeUser('Unit Viewer');
        [$business, , $roles] = $this->provision($user, 'Read Unit Co.', 'viewer');
        $this->enableProducts($business);

        $viewId = Permission::where('name', 'units.view')->value('id');
        $roles['viewer']->permissions()->syncWithoutDetaching([$viewId]);
        $this->rebuildContext();

        $this->actIn($user, $business);
        $this->assertTrue(Gate::allows('units.view'));
        $this->assertFalse(Gate::allows('units.manage'));

        $unit = $this->makeUnit($business, ['name' => 'Readable Unit']);

        $this->get('/units')
            ->assertOk()
            ->assertSee('Readable Unit')
            ->assertSee(__('units.view_only'));

        $this->get(route('units.create'))->assertForbidden();
        $this->post('/units', ['name' => 'Nope'])->assertForbidden();
        $this->get(route('units.edit', $unit))->assertForbidden();
        $this->patch(route('units.update', $unit), ['name' => 'Nope'])->assertForbidden();
        $this->delete(route('units.destroy', $unit))->assertForbidden();

        $this->assertSame('Readable Unit', $unit->fresh()->name);
        $this->assertDatabaseCount('units', 1);
    }

    // --- Owner/manager happy path --------------------------------------------

    public function test_owner_can_create_view_update_and_soft_delete_a_unit(): void
    {
        $user = $this->makeUser('Unit Owner 2');
        [$business] = $this->provision($user, 'Own Unit Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->post('/units', [
            'name' => 'Piece',
            'short_name' => 'pcs',
        ])->assertRedirect(route('units.index'))->assertSessionHas('status', __('units.created'));

        $unit = Unit::where('name', 'Piece')->firstOrFail();
        $this->assertSame($business->id, $unit->business_id);
        $this->assertSame('pcs', $unit->short_name);

        $this->patch(route('units.update', $unit), [
            'name' => 'Kilogram',
            'short_name' => 'kg',
        ])->assertRedirect(route('units.index'))->assertSessionHas('status', __('units.updated'));

        $this->assertSame('Kilogram', $unit->fresh()->name);
        $this->assertSame('kg', $unit->fresh()->short_name);

        $this->delete(route('units.destroy', $unit))
            ->assertRedirect(route('units.index'))
            ->assertSessionHas('status', __('units.deleted'));

        $this->assertSoftDeleted('units', ['id' => $unit->id]);
        $this->get('/units')->assertOk()->assertDontSee('Kilogram');
    }

    public function test_admin_role_can_manage_units(): void
    {
        $user = $this->makeUser('Unit Admin');
        [$business] = $this->provision($user, 'Admin Unit Co.', 'admin');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->assertTrue(Gate::allows('units.manage'));

        $this->post('/units', ['name' => 'Managed Unit'])->assertRedirect();
        $this->assertDatabaseHas('units', ['business_id' => $business->id, 'name' => 'Managed Unit']);
    }

    // --- Tenancy: forged identifiers are ignored -----------------------------

    public function test_create_ignores_forged_business_id(): void
    {
        $user = $this->makeUser('Unit Forger');
        [$business] = $this->provision($user, 'Forge Unit Co.', 'owner');
        $this->enableProducts($business);
        $other = $this->makeBusiness('Victim Unit Co.');

        $this->actIn($user, $business);

        $this->post('/units', [
            'name' => 'Legit Unit',
            'business_id' => $other->id,
            'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseHas('units', ['name' => 'Legit Unit', 'business_id' => $business->id]);
        $this->assertDatabaseMissing('units', ['business_id' => $other->id]);
        $unit = Unit::where('name', 'Legit Unit')->firstOrFail();
        $this->assertArrayNotHasKey('status', $unit->getAttributes());
    }

    public function test_update_ignores_forged_business_id(): void
    {
        $user = $this->makeUser('Unit Up Forger');
        [$business] = $this->provision($user, 'Up Forge Unit Co.', 'owner');
        $this->enableProducts($business);
        $other = $this->makeBusiness('Other Victim Unit Co.');
        $unit = $this->makeUnit($business, ['name' => 'Stable Unit']);

        $this->actIn($user, $business);

        $this->patch(route('units.update', $unit), [
            'name' => 'Stable Unit',
            'business_id' => $other->id,
        ])->assertRedirect(route('units.index'));

        $this->assertSame($business->id, $unit->fresh()->business_id);
        $this->assertDatabaseMissing('units', ['business_id' => $other->id]);
    }

    // --- Isolation: index + search are tenant-scoped --------------------------

    public function test_index_and_search_show_only_current_business_units(): void
    {
        $user = $this->makeUser('Unit Multi');
        [$businessA] = $this->provision($user, 'Alpha Unit Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Unit Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $this->makeUnit($businessA, ['name' => 'Piece', 'short_name' => 'pcs']);
        $this->makeUnit($businessA, ['name' => 'Box']);
        $this->makeUnit($businessB, ['name' => 'Beta Piece', 'short_name' => 'pc']);

        $this->actIn($user, $businessA);

        $this->get('/units')
            ->assertOk()
            ->assertSee('Piece')
            ->assertSee('Box')
            ->assertDontSee('Beta Piece');

        // Search matches by name OR short_name, scoped to the current business.
        $this->get('/units?search=pcs')
            ->assertOk()
            ->assertSee('Piece')
            ->assertDontSee('Beta Piece');
        $this->assertDatabaseHas('units', ['business_id' => $businessB->id, 'name' => 'Beta Piece']);
    }

    public function test_cross_business_edit_update_and_delete_are_blocked(): void
    {
        $user = $this->makeUser('Unit Cross');
        [$businessA] = $this->provision($user, 'Alpha Unit Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Unit Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);
        $foreign = $this->makeUnit($businessB, ['name' => 'Foreign Unit']);

        $this->actIn($user, $businessA);

        $this->get(route('units.edit', $foreign))->assertNotFound();
        $this->patch(route('units.update', $foreign), ['name' => 'Hijacked'])->assertNotFound();
        $this->delete(route('units.destroy', $foreign))->assertNotFound();

        $this->assertSame('Foreign Unit', $foreign->fresh()->name);
        $this->assertFalse($foreign->fresh()->trashed());
    }

    public function test_multi_business_switch_isolation(): void
    {
        $user = $this->makeUser('Unit Switch');
        [$businessA] = $this->provision($user, 'Alpha Unit Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Unit Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $alpha = $this->makeUnit($businessA, ['name' => 'Litre Unit']);
        $beta = $this->makeUnit($businessB, ['name' => 'Kilo Unit']);

        $this->actIn($user, $businessA);
        $this->get('/units')->assertOk()->assertSee('Litre Unit')->assertDontSee('Kilo Unit');
        $this->get(route('units.edit', $beta))->assertNotFound();

        $this->post(route('business.switch'), ['business_id' => $businessB->id])->assertRedirect(route('app.home'));

        $this->get('/units')->assertOk()->assertSee('Kilo Unit')->assertDontSee('Litre Unit');
        $this->get(route('units.edit', $beta))->assertOk();

        $this->patch(route('units.update', $alpha), ['name' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Litre Unit', $alpha->fresh()->name);
    }

    // --- Business-scoped uniqueness (soft-delete aware) -----------------------

    public function test_unit_names_are_unique_within_business_but_not_across_businesses(): void
    {
        $user = $this->makeUser('Unit Unique');
        [$businessA] = $this->provision($user, 'Alpha Unit Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Unit Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $this->actIn($user, $businessA);
        $this->makeUnit($businessA, ['name' => 'Shared Unit']);

        $this->post('/units', ['name' => 'Shared Unit'])
            ->assertSessionHasErrors('name');

        $this->actIn($user, $businessB);
        $this->post('/units', ['name' => 'Shared Unit'])->assertRedirect();
        $this->assertDatabaseHas('units', ['business_id' => $businessB->id, 'name' => 'Shared Unit']);

        $this->patch(route('units.update', Unit::where('business_id', $businessB->id)->firstOrFail()), [
            'name' => 'Shared Unit',
        ])->assertRedirect(route('units.index'));

        $this->assertDatabaseCount('units', 2);
    }

    public function test_soft_deleted_unit_does_not_block_recreating_the_same_name(): void
    {
        $user = $this->makeUser('Unit Recycle');
        [$business] = $this->provision($user, 'Recycle Unit Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $unit = $this->makeUnit($business, ['name' => 'Reusable Unit']);
        $this->delete(route('units.destroy', $unit))->assertRedirect(route('units.index'));
        $this->assertSoftDeleted('units', ['id' => $unit->id]);

        $this->post('/units', ['name' => 'Reusable Unit'])->assertRedirect();
        $this->assertDatabaseCount('units', 2);
        $this->assertDatabaseHas('units', ['business_id' => $business->id, 'name' => 'Reusable Unit', 'deleted_at' => null]);
    }

    // --- Validation ----------------------------------------------------------

    public function test_unit_validation_rejects_invalid_input(): void
    {
        $user = $this->makeUser('Unit Valid');
        [$business] = $this->provision($user, 'Valid Unit Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->post('/units', ['name' => ''])
            ->assertSessionHasErrors(['name' => __('units.validation.name_required')]);

        $this->post('/units', ['name' => str_repeat('a', 101)])
            ->assertSessionHasErrors(['name' => __('units.validation.name_max')]);

        $this->post('/units', ['name' => 'Ok', 'short_name' => str_repeat('a', 21)])
            ->assertSessionHasErrors(['short_name' => __('units.validation.short_name_max')]);

        $this->assertDatabaseCount('units', 0);
    }

    // --- Pagination with search preservation ---------------------------------

    public function test_pagination_preserves_search_query(): void
    {
        $user = $this->makeUser('Unit Pager');
        [$business] = $this->provision($user, 'Page Unit Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        for ($i = 1; $i <= 20; $i++) {
            $this->makeUnit($business, ['name' => sprintf('Alpha Unit %02d', $i)]);
        }
        $this->makeUnit($business, ['name' => 'Beta Unit']);

        $this->get('/units?search=Alpha')
            ->assertOk()
            ->assertSee('Alpha Unit 01')
            ->assertDontSee('Beta Unit')
            ->assertSee('search=Alpha', false);

        $this->get('/units?search=Alpha&page=2')
            ->assertOk()
            ->assertSee('Alpha Unit 16');
    }

    // --- Navigation points at the real route ----------------------------------

    public function test_unit_navigation_uses_the_real_route(): void
    {
        $children = config('modules.registry.products.navigation.children');
        $this->assertIsArray($children);
        $this->assertSame('units.index', $children[1]['route']);
        $this->assertSame('units.view', $children[1]['permission']);

        $user = $this->makeUser('Unit Nav Owner');
        [$business] = $this->provision($user, 'Nav Unit Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->get('/units')
            ->assertOk()
            ->assertSee(route('units.index'), false);
    }
}
