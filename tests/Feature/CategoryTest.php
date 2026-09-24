<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Category;
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

class CategoryTest extends TestCase
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

    private function makeUser(string $name = 'Category User'): User
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
     * The products module is NOT enabled by default (config/modules.php);
     * categories ship under it, so each test explicitly activates it.
     */
    private function enableProducts(Business $business): void
    {
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => 'products'],
            ['enabled' => true],
        );
    }

    /**
     * Create a category fixture owned by the given business.
     *
     * business_id is never mass assignable (tenancy invariant), so it is set
     * explicitly here as a trusted internal path — exactly what a factory or
     * seeder would do.
     */
    private function makeCategory(Business $business, array $attributes = []): Category
    {
        $category = new Category(array_merge(['name' => 'Category '.Str::random(5)], $attributes));
        $category->business_id = $business->id;
        $category->save();

        return $category;
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

    public function test_category_schema_is_in_place(): void
    {
        $this->assertTrue(Schema::hasTable('categories'));

        foreach (['business_id', 'name', 'description', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('categories', $column), "Expected categories.$column to exist.");
        }
    }

    // --- Route guarding: auth + business + module + permission ---------------

    public function test_category_routes_require_authentication_and_a_business(): void
    {
        $this->get('/categories')->assertRedirect(route('login'));
        $this->post('/categories', ['name' => 'Nope'])->assertRedirect(route('login'));

        // Authenticated but with no business: onboarding state, never the list.
        $user = $this->makeUser();
        $this->actingAs($user)->get('/categories')->assertRedirect(route('business.create'));
    }

    public function test_category_module_guard_blocks_when_disabled_and_keeps_data_intact(): void
    {
        $user = $this->makeUser('Cat Owner');
        [$business] = $this->provision($user, 'Cat Co.', 'owner');
        $this->enableProducts($business);
        $category = $this->makeCategory($business, ['name' => 'Kept Category']);

        $this->actIn($user, $business);
        $this->get('/categories')->assertOk()->assertSee('Kept Category');

        // Disable the module: the same owner is refused outright, even by URL.
        $business->modules()->where('module_key', 'products')->update(['enabled' => false]);
        $this->get('/categories')->assertForbidden();
        $this->post('/categories', ['name' => 'Blocked'])->assertForbidden();

        // Data is untouched by disabling the module.
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'business_id' => $business->id, 'deleted_at' => null]);

        // Re-enabling restores access for the owner.
        $business->modules()->where('module_key', 'products')->update(['enabled' => true]);
        $this->get('/categories')->assertOk()->assertSee('Kept Category');
    }

    public function test_reads_require_categories_view_and_writes_require_categories_manage(): void
    {
        $user = $this->makeUser('No Perm');
        [$business] = $this->provision($user, 'No Perm Co.', 'viewer');
        $this->enableProducts($business);

        $this->actIn($user, $business);

        // The default viewer role holds neither category permission.
        $this->assertFalse(Gate::allows('categories.view'));
        $this->get('/categories')->assertForbidden();
        $this->get(route('categories.create'))->assertForbidden();
    }

    public function test_permission_and_module_boundaries_are_independent(): void
    {
        $user = $this->makeUser('Indep Owner');
        [$business, , $roles] = $this->provision($user, 'Indep Co.', 'owner');
        $owner = $roles['owner'];
        $this->assertTrue($owner->hasPermission('categories.view'));
        $this->assertTrue($owner->hasPermission('categories.manage'));

        // Module disabled: forbidden even though the role holds permissions.
        $this->actIn($user, $business);
        $this->get('/categories')->assertForbidden();
        $this->get(route('categories.create'))->assertForbidden();

        // Module enabled but a permission-less viewer: still forbidden.
        $this->enableProducts($business);
        $viewer = $this->makeUser('No Perm Viewer');
        $this->provision($viewer, 'Indep Co.', 'viewer');
        $this->actIn($viewer, $business);
        $this->assertFalse(Gate::allows('categories.view'));
        $this->get('/categories')->assertForbidden();
    }

    // --- Mandatory authorization scenario: view without manage ----------------

    public function test_member_with_view_only_can_read_but_cannot_modify(): void
    {
        $user = $this->makeUser('Cat Viewer');
        [$business, , $roles] = $this->provision($user, 'Read Cat Co.', 'viewer');
        $this->enableProducts($business);

        // Grant read-only access to the otherwise permission-less viewer role.
        $viewId = Permission::where('name', 'categories.view')->value('id');
        $roles['viewer']->permissions()->syncWithoutDetaching([$viewId]);
        $this->rebuildContext();

        $this->actIn($user, $business);
        $this->assertTrue(Gate::allows('categories.view'));
        $this->assertFalse(Gate::allows('categories.manage'));

        $category = $this->makeCategory($business, ['name' => 'Readable Category']);

        // Reads work and the read-only notice is shown.
        $this->get('/categories')
            ->assertOk()
            ->assertSee('Readable Category')
            ->assertSee(__('categories.view_only'));

        // Every write path is denied before validation.
        $this->get(route('categories.create'))->assertForbidden();
        $this->post('/categories', ['name' => 'Nope'])->assertForbidden();
        $this->get(route('categories.edit', $category))->assertForbidden();
        $this->patch(route('categories.update', $category), ['name' => 'Nope'])->assertForbidden();
        $this->delete(route('categories.destroy', $category))->assertForbidden();

        $this->assertSame('Readable Category', $category->fresh()->name);
        $this->assertDatabaseCount('categories', 1);
    }

    // --- Owner/manager happy path --------------------------------------------

    public function test_owner_can_create_view_update_and_soft_delete_a_category(): void
    {
        $user = $this->makeUser('Cat Owner 2');
        [$business] = $this->provision($user, 'Own Cat Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->post('/categories', [
            'name' => 'Created Category',
            'description' => 'Hardware spares.',
        ])->assertRedirect(route('categories.index'))->assertSessionHas('status', __('categories.created'));

        $category = Category::where('name', 'Created Category')->firstOrFail();
        $this->assertSame($business->id, $category->business_id);
        $this->assertSame('Hardware spares.', $category->description);

        $this->patch(route('categories.update', $category), [
            'name' => 'Renamed Category',
            'description' => null,
        ])->assertRedirect(route('categories.index'))->assertSessionHas('status', __('categories.updated'));

        $this->assertSame('Renamed Category', $category->fresh()->name);
        $this->assertNull($category->fresh()->description);

        // Soft delete: the row remains, only deleted_at is set.
        $this->delete(route('categories.destroy', $category))
            ->assertRedirect(route('categories.index'))
            ->assertSessionHas('status', __('categories.deleted'));

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
        $this->get('/categories')->assertOk()->assertDontSee('Renamed Category');
    }

    public function test_admin_role_can_manage_categories(): void
    {
        $user = $this->makeUser('Cat Admin');
        [$business] = $this->provision($user, 'Admin Cat Co.', 'admin');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->assertTrue(Gate::allows('categories.manage'));

        $this->post('/categories', ['name' => 'Managed Category'])->assertRedirect();
        $this->assertDatabaseHas('categories', ['business_id' => $business->id, 'name' => 'Managed Category']);
    }

    // --- Tenancy: forged identifiers are ignored -----------------------------

    public function test_create_ignores_forged_business_id(): void
    {
        $user = $this->makeUser('Cat Forger');
        [$business] = $this->provision($user, 'Forge Cat Co.', 'owner');
        $this->enableProducts($business);
        $other = $this->makeBusiness('Victim Cat Co.');

        $this->actIn($user, $business);

        $this->post('/categories', [
            'name' => 'Legit Category',
            'business_id' => $other->id,
            'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseHas('categories', ['name' => 'Legit Category', 'business_id' => $business->id]);
        $this->assertDatabaseMissing('categories', ['business_id' => $other->id]);
        // Unknown keys never persist (there is no `status` column).
        $category = Category::where('name', 'Legit Category')->firstOrFail();
        $this->assertArrayNotHasKey('status', $category->getAttributes());
    }

    public function test_update_ignores_forged_business_id(): void
    {
        $user = $this->makeUser('Cat Up Forger');
        [$business] = $this->provision($user, 'Up Forge Cat Co.', 'owner');
        $this->enableProducts($business);
        $other = $this->makeBusiness('Other Victim Cat Co.');
        $category = $this->makeCategory($business, ['name' => 'Stable Category']);

        $this->actIn($user, $business);

        $this->patch(route('categories.update', $category), [
            'name' => 'Stable Category',
            'business_id' => $other->id,
        ])->assertRedirect(route('categories.index'));

        $this->assertSame($business->id, $category->fresh()->business_id);
        $this->assertDatabaseMissing('categories', ['business_id' => $other->id]);
    }

    // --- Isolation: index + search are tenant-scoped --------------------------

    public function test_index_and_search_show_only_current_business_categories(): void
    {
        $user = $this->makeUser('Cat Multi Owner');
        [$businessA] = $this->provision($user, 'Alpha Cat Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Cat Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $this->makeCategory($businessA, ['name' => 'Alpha Searchable']);
        $this->makeCategory($businessA, ['name' => 'Alpha Other']);
        $this->makeCategory($businessB, ['name' => 'Beta Searchable']);

        $this->actIn($user, $businessA);

        $this->get('/categories')
            ->assertOk()
            ->assertSee('Alpha Searchable')
            ->assertSee('Alpha Other')
            ->assertDontSee('Beta Searchable');

        // Search matches only the current business, even for an identical term.
        $this->get('/categories?search=Searchable')
            ->assertOk()
            ->assertSee('Alpha Searchable')
            ->assertDontSee('Beta Searchable');
    }

    public function test_cross_business_edit_update_and_delete_are_blocked(): void
    {
        $user = $this->makeUser('Cat Cross Owner');
        [$businessA] = $this->provision($user, 'Alpha Cat Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Cat Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);
        $foreign = $this->makeCategory($businessB, ['name' => 'Foreign Category']);

        $this->actIn($user, $businessA);

        $this->get(route('categories.edit', $foreign))->assertNotFound();
        $this->patch(route('categories.update', $foreign), ['name' => 'Hijacked'])->assertNotFound();
        $this->delete(route('categories.destroy', $foreign))->assertNotFound();

        $this->assertSame('Foreign Category', $foreign->fresh()->name);
        $this->assertFalse($foreign->fresh()->trashed());
    }

    public function test_multi_business_switch_isolation(): void
    {
        $user = $this->makeUser('Cat Switch Owner');
        [$businessA] = $this->provision($user, 'Alpha Cat Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Cat Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $alpha = $this->makeCategory($businessA, ['name' => 'Alpha Category']);
        $beta = $this->makeCategory($businessB, ['name' => 'Beta Category']);

        $this->actIn($user, $businessA);
        $this->get('/categories')->assertOk()->assertSee('Alpha Category')->assertDontSee('Beta Category');
        $this->get(route('categories.edit', $alpha))->assertOk();
        $this->get(route('categories.edit', $beta))->assertNotFound();

        // Switch to B via the real switch endpoint.
        $this->post(route('business.switch'), ['business_id' => $businessB->id])->assertRedirect(route('app.home'));

        $this->get('/categories')->assertOk()->assertSee('Beta Category')->assertDontSee('Alpha Category');
        $this->get(route('categories.edit', $beta))->assertOk();
        $this->get(route('categories.edit', $alpha))->assertNotFound();

        // A write targeting A's category while acting in B must not leak.
        $this->patch(route('categories.update', $alpha), ['name' => 'Hijacked'])->assertNotFound();
        $this->assertSame('Alpha Category', $alpha->fresh()->name);
    }

    // --- Business-scoped uniqueness (soft-delete aware) -----------------------

    public function test_category_names_are_unique_within_business_but_not_across_businesses(): void
    {
        $user = $this->makeUser('Cat Unique');
        [$businessA] = $this->provision($user, 'Alpha Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $this->actIn($user, $businessA);
        $this->makeCategory($businessA, ['name' => 'Shared Name']);

        // No duplicate inside the same business.
        $this->post('/categories', ['name' => 'Shared Name'])
            ->assertSessionHasErrors('name');

        // Same name in a different business is allowed.
        $this->actIn($user, $businessB);
        $this->post('/categories', ['name' => 'Shared Name'])->assertRedirect();
        $this->assertDatabaseHas('categories', ['business_id' => $businessB->id, 'name' => 'Shared Name']);

        // Editing keeps the same name (ignore self).
        $this->patch(route('categories.update', Category::where('business_id', $businessB->id)->firstOrFail()), [
            'name' => 'Shared Name',
        ])->assertRedirect(route('categories.index'));

        $this->assertDatabaseCount('categories', 2);
    }

    public function test_soft_deleted_category_does_not_block_recreating_the_same_name(): void
    {
        $user = $this->makeUser('Cat Recycle');
        [$business] = $this->provision($user, 'Recycle Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $category = $this->makeCategory($business, ['name' => 'Reusable Name']);
        $this->delete(route('categories.destroy', $category))->assertRedirect(route('categories.index'));
        $this->assertSoftDeleted('categories', ['id' => $category->id]);

        // soft-deleted rows never block re-use of the value.
        $this->post('/categories', ['name' => 'Reusable Name'])->assertRedirect();
        $this->assertDatabaseCount('categories', 2);
        $this->assertDatabaseHas('categories', ['business_id' => $business->id, 'name' => 'Reusable Name', 'deleted_at' => null]);
    }

    // --- Validation ----------------------------------------------------------

    public function test_category_validation_rejects_invalid_input(): void
    {
        $user = $this->makeUser('Cat Valid');
        [$business] = $this->provision($user, 'Valid Cat Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->post('/categories', ['name' => ''])
            ->assertSessionHasErrors(['name' => __('categories.validation.name_required')]);

        $this->post('/categories', ['name' => str_repeat('a', 101)])
            ->assertSessionHasErrors(['name' => __('categories.validation.name_max')]);

        $this->post('/categories', ['name' => 'Ok', 'description' => str_repeat('a', 501)])
            ->assertSessionHasErrors(['description' => __('categories.validation.description_max')]);

        $this->assertDatabaseCount('categories', 0);
    }

    // --- Pagination with search preservation ---------------------------------

    public function test_pagination_preserves_search_query(): void
    {
        $user = $this->makeUser('Cat Pager');
        [$business] = $this->provision($user, 'Page Cat Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        for ($i = 1; $i <= 20; $i++) {
            $this->makeCategory($business, ['name' => sprintf('Alpha Category %02d', $i)]);
        }
        $this->makeCategory($business, ['name' => 'Beta Category']);

        $this->get('/categories?search=Alpha')
            ->assertOk()
            ->assertSee('Alpha Category 01')
            ->assertDontSee('Beta Category')
            ->assertSee('search=Alpha', false);

        $this->get('/categories?search=Alpha&page=2')
            ->assertOk()
            ->assertSee('Alpha Category 16');
    }

    // --- Navigation points at the real route ----------------------------------

    public function test_category_navigation_uses_the_real_route(): void
    {
        $children = config('modules.registry.products.navigation.children');
        $this->assertIsArray($children);
        $this->assertSame('categories.index', $children[0]['route']);
        $this->assertSame('categories.view', $children[0]['permission']);

        $user = $this->makeUser('Cat Nav Owner');
        [$business] = $this->provision($user, 'Nav Cat Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->get('/categories')
            ->assertOk()
            ->assertSee(route('categories.index'), false);
    }
}
