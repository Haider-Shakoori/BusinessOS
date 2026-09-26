<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\User;
use App\Services\BusinessContext;
use App\Services\ModuleManager;
use App\Services\ModuleRegistry;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModuleSystemTest extends TestCase
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

    private function makeUser(string $name = 'Module User'): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function makeBusiness(string $name): Business
    {
        return Business::create(['name' => $name]);
    }

    /**
     * Provision a business with default roles + default modules and attach the
     * user with the given default-role slug. Returns [business, membership].
     *
     * @return array{0: Business, 1: BusinessMembership}
     */
    private function provision(User $user, string $businessName, string $roleSlug): array
    {
        $business = $this->makeBusiness($businessName);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles[$roleSlug]);

        return [$business, $membership];
    }

    private function sessionKey(): string
    {
        return config('business.context.session_key');
    }

    private function rebuildContext(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function enter(User $user, Business $business): void
    {
        $this->actingAs($user)->withSession([$this->sessionKey() => $business->id]);
        $this->rebuildContext();
    }

    private function enableModule(Business $business, string $key, bool $enabled = true): void
    {
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => $key],
            ['enabled' => $enabled],
        );
    }

    private function registerProtectedRoute(string $uri, string $module, ?string $permission = null): void
    {
        $middlewares = ['web', 'module:'.$module];

        if ($permission !== null) {
            $middlewares[] = 'permission:'.$permission;
        }

        Route::get($uri, fn () => response('ALLOWED'))->middleware($middlewares);
    }

    // --- Verification items 1-3: schema, registry, unknown modules ----------

    public function test_module_schema_and_registry_are_in_place(): void
    {
        $this->assertTrue(Schema::hasTable('business_modules'));

        $registry = app(ModuleRegistry::class);
        $keys = array_keys($registry->all());
        $this->assertSame(
            ['dashboard', 'customers', 'sales', 'products', 'expenses', 'inventory', 'purchasing', 'accounting', 'manufacturing', 'crm', 'reports', 'settings'],
            $keys,
        );

        $this->assertTrue($registry->exists('customers'));
        $this->assertFalse($registry->exists('nonsense'));
        $this->assertSame('modules.customers', $registry->find('customers')['label']);
        $this->assertNull($registry->find('nonsense'));
    }

    public function test_unknown_modules_fail_safely_and_middleware_rejects_them(): void
    {
        $this->registerProtectedRoute('/__module/unknown', 'nonsense');

        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Safe Co.', 'owner');

        // A database row for an unregistered key stays inert: an unregistered
        // module is never treated as available.
        $this->enableModule($business, 'nonsense');
        $this->enter($user, $business);
        $this->assertFalse(app(ModuleRegistry::class)->exists('nonsense'));
        $this->assertFalse(app(ModuleManager::class)->isEnabled('nonsense'));

        // Route middleware denies an unregistered module key outright.
        $this->get('/__module/unknown')->assertForbidden();
    }

    // --- Verification items 4-5: default provisioning, idempotency ----------

    public function test_default_modules_provision_correctly_and_are_idempotent(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Defaults Co.', 'owner');

        // Only the shell foundation is enabled by default (no customers etc).
        $this->assertSame(['dashboard', 'settings'], $business->modules()->orderBy('id')->pluck('module_key')->all());

        $this->enter($user, $business);
        $this->assertSame(['dashboard', 'settings'], app(ModuleManager::class)->enabledModules());

        // Re-running provisioning adds nothing and changes nothing.
        $business->provisionDefaultModules();
        $business->provisionDefaultModules();
        $this->assertDatabaseCount('business_modules', 2);
        $this->assertSame(['dashboard', 'settings'], $business->modules()->orderBy('id')->pluck('module_key')->all());
    }

    // --- Verification item 22: onboarding creates everything atomically -----

    public function test_onboarding_creates_business_membership_roles_and_default_modules_atomically(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('business.store'), ['name' => 'Onboard Co.'])
            ->assertRedirect(route('app.home'));

        $business = Business::where('name', 'Onboard Co.')->firstOrFail();

        $this->assertDatabaseHas('business_memberships', ['business_id' => $business->id, 'user_id' => $user->id]);
        $this->assertDatabaseHas('business_membership_role', [
            'membership_id' => $user->memberships()->where('business_id', $business->id)->first()->id,
            'role_id' => $business->roles()->where('slug', 'owner')->first()->id,
        ]);
        $this->assertSame(['dashboard', 'settings'], $business->modules()->orderBy('id')->pluck('module_key')->all());
    }

    // --- Verification items 6-7: enabled / disabled -------------------------

    public function test_module_service_reports_enabled_and_disabled_state(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'State Co.', 'owner');

        $this->enter($user, $business);

        // Default modules are enabled; non-provisioned registered modules are not.
        $this->assertTrue(app(ModuleManager::class)->isEnabled('dashboard'));
        $this->assertFalse(app(ModuleManager::class)->isEnabled('customers'));

        $this->enableModule($business, 'customers');
        $this->rebuildContext();
        $this->assertTrue(app(ModuleManager::class)->isEnabled('customers'));

        $this->enableModule($business, 'customers', false);
        $this->rebuildContext();
        $this->assertFalse(app(ModuleManager::class)->isEnabled('customers'));
    }

    // --- Verification items 9-10: module middleware -------------------------

    public function test_module_middleware_allows_enabled_and_blocks_disabled_modules(): void
    {
        $this->registerProtectedRoute('/__module/customers', 'customers');

        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Midware Co.', 'owner');
        $this->enableModule($business, 'customers');

        $this->enter($user, $business);
        $this->get('/__module/customers')->assertOk();

        $this->enableModule($business, 'customers', false);
        $this->get('/__module/customers')->assertForbidden();
    }

    // --- Verification item 11: module middleware is not authorization -------

    public function test_module_middleware_does_not_bypass_permission_checks(): void
    {
        $this->registerProtectedRoute('/__module/customers', 'customers', 'customers.view');

        $business = $this->makeBusiness('Compose Co.');
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();
        $this->enableModule($business, 'customers');

        // Owner holds customers.view -> module enabled AND permission granted.
        $owner = $this->makeUser('Owner');
        $owner->memberships()->create(['business_id' => $business->id])->assignRole($roles['owner']);
        $this->enter($owner, $business);
        $this->get('/__module/customers')->assertOk();

        // A member with no role holds no customers.view -> module permits, permission denies.
        $guest = $this->makeUser('No-Permission');
        $guest->memberships()->create(['business_id' => $business->id]);
        $this->flushSession();
        $this->enter($guest, $business);
        $this->get('/__module/customers')->assertForbidden();

        // Module disabled + valid permission still denies (module wins first).
        $this->enableModule($business, 'customers', false);
        $this->get('/__module/customers')->assertForbidden();
    }

    // --- Verification item 21: mandatory module vs permission -----------------

    public function test_module_enablement_is_not_authorization(): void
    {
        $this->registerProtectedRoute('/__module/customers', 'customers', 'customers.view');

        $business = $this->makeBusiness('Perm Co.');
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();
        $this->enableModule($business, 'customers');

        $member = $this->makeUser('Read-Only');
        $membership = $member->memberships()->create(['business_id' => $business->id]);

        // The module IS enabled for the business...
        $this->enter($member, $business);
        $this->assertTrue(app(ModuleManager::class)->isEnabled('customers'));

        // ...but the backend permission still denies access (module != authorization).
        $this->get('/__module/customers')->assertForbidden();

        // The approved final-product shell keeps its menu blueprint visible,
        // but visibility never grants backend access.
        $this->get('/app')->assertOk()->assertSee(__('navigation.customers'));

        // Grant the permission: module enabled + permission granted => allowed.
        $membership->assignRole($roles['viewer']);
        $this->rebuildContext();

        $this->get('/__module/customers')->assertOk();
        $this->get('/app')->assertOk()->assertSee(__('modules.customers'));
    }

    // --- Verification items 12-14, 20: navigation + multi-business ----------

    public function test_navigation_blueprint_stays_visible_while_module_state_controls_access(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Nav Co.', 'owner');

        $this->enter($user, $business);

        // Screenshot-authoritative menu: Customers remains visible even while
        // its module is disabled.
        $this->get('/app')->assertOk()->assertSee(__('navigation.customers'));
        $this->assertFalse(app(ModuleManager::class)->isEnabled('customers'));

        // Enable Customers: the same row becomes actionable.
        $this->enableModule($business, 'customers');
        $this->rebuildContext();
        $this->assertTrue(app(ModuleManager::class)->isEnabled('customers'));
        $this->get('/customers')->assertOk();
        $this->get('/app')->assertOk()->assertSee(__('navigation.customers'));

        // Disable again: shell order stays stable while middleware refuses URL access.
        $this->enableModule($business, 'customers', false);
        $this->rebuildContext();
        $this->assertFalse(app(ModuleManager::class)->isEnabled('customers'));
        $this->get('/customers')->assertForbidden();
        $this->get('/app')->assertOk()->assertSee(__('navigation.customers'));
    }

    public function test_navigation_visibility_never_bypasses_missing_permissions(): void
    {
        $business = $this->makeBusiness('Scope Nav Co.');
        $business->provisionDefaultRoles();
        $business->provisionDefaultModules();
        $this->enableModule($business, 'customers');

        // The row remains in the approved shell, but a member without a role
        // still cannot open the protected module.
        $member = $this->makeUser('Read-Only');
        $member->memberships()->create(['business_id' => $business->id]);

        $this->enter($member, $business);
        $this->get('/app')->assertOk()->assertSee(__('navigation.customers'));
        $this->get('/customers')->assertForbidden();
    }

    public function test_mandatory_multi_business_module_switching(): void
    {
        $this->registerProtectedRoute('/__module/customers', 'customers');

        $user = $this->makeUser('Modular');
        [$businessA] = $this->provision($user, 'Alpha Holdings', 'owner');
        [, $membershipB] = $this->provision($user, 'Beta Holdings', 'owner');
        $businessB = $membershipB->business;

        // Business A: customers ENABLED. Business B: customers DISABLED.
        $this->enableModule($businessA, 'customers');
        $this->assertDatabaseMissing('business_modules', ['business_id' => $businessB->id, 'module_key' => 'customers']);

        // Context at Business A: enabled, navigation eligible, middleware permits.
        $this->enter($user, $businessA);
        $this->get('/__module/customers')->assertOk();
        $this->get('/app')->assertOk()->assertSee(__('modules.customers'));

        // Switch to Business B: module is disabled. The fixed product menu
        // remains visible, while middleware blocks actual module access.
        $this->post(route('business.switch'), ['business_id' => $businessB->id])->assertRedirect(route('app.home'));
        $this->get('/__module/customers')->assertForbidden();
        $this->get('/app')->assertOk()->assertSee(__('navigation.customers'));

        // Switch back to Business A: behavior restored.
        $this->post(route('business.switch'), ['business_id' => $businessA->id])->assertRedirect(route('app.home'));
        $this->get('/__module/customers')->assertOk();
        $this->get('/app')->assertOk()->assertSee(__('modules.customers'));
        $this->assertSame($businessA->id, app(BusinessContext::class)->currentId());
    }

    // --- Verification item 15: forged/stale context cannot leak modules -----

    public function test_module_state_cannot_leak_via_forged_business_context(): void
    {
        $this->registerProtectedRoute('/__module/customers', 'customers');

        $userA = $this->makeUser('Alice');
        $userB = $this->makeUser('Bob');
        [$businessA] = $this->provision($userA, 'Alpha Co.', 'owner');
        [, $membershipB] = $this->provision($userB, 'Beta Co.', 'owner');
        $businessB = $membershipB->business;

        // Alpha has customers DISABLED, Beta has it ENABLED.
        $this->assertDatabaseMissing('business_modules', ['business_id' => $businessA->id, 'module_key' => 'customers']);
        $this->enableModule($businessB, 'customers');

        // Alice forges the session to Beta: resolution falls back to her own
        // business and Beta's module state never leaks in.
        $this->actingAs($userA)->withSession([$this->sessionKey() => $businessB->id])
            ->get('/__module/customers')->assertForbidden();
        $this->assertSame($businessA->id, session($this->sessionKey()));

        // Bob legitimately operates inside Beta where customers IS enabled.
        $this->flushSession();
        $this->actingAs($userB)->withSession([$this->sessionKey() => $businessB->id])
            ->get('/__module/customers')->assertOk();
    }
}
