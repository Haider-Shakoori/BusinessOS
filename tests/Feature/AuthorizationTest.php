<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BusinessContext;
use App\Services\MembershipAuthorization;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthorizationTest extends TestCase
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

    private function makeUser(string $name = 'Authz User'): User
    {
        return User::create(['name' => $name, 'email' => Str::random(10).'@example.test', 'password' => Hash::make('password')]);
    }

    private function makeBusiness(string $name): Business
    {
        return Business::create(['name' => $name]);
    }

    /**
     * Provision a business with default roles and attach the given user with
     * the given default-role slug. Returns [business, membership, roles].
     *
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

    private function sessionKey(): string
    {
        return config('business.context.session_key');
    }

    /**
     * Re-create the request-lifecycle context so direct Gate/service assertions
     * observe the currently acting user and the stored current business.
     */
    private function rebuildContext(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function registerProtectedRoute(string $uri, string $permission): void
    {
        Route::get($uri, fn () => response('ALLOWED-'.$permission))
            ->middleware(['web', 'permission:'.$permission]);
    }

    // --- Verification items 1-3: initial roles on onboarding ---------------

    public function test_onboarding_grants_owner_role_and_provisions_default_roles_atomically(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->post(route('business.store'), ['name' => 'Acme Inc.'])
            ->assertRedirect(route('app.home'));

        $business = Business::where('name', 'Acme Inc.')->firstOrFail();
        $membership = $user->memberships()->where('business_id', $business->id)->firstOrFail();

        // Exactly the minimal default role set, scoped to this business.
        $this->assertDatabaseCount('roles', 3);
        $this->assertSame(['owner', 'admin', 'viewer'], $business->roles()->orderBy('id')->pluck('slug')->all());

        // The creator is attached exactly once and owns the Owner role.
        $this->assertDatabaseCount('business_memberships', 1);
        $this->assertTrue($membership->roles()->where('slug', 'owner')->exists());
        $this->assertSame($business->id, $membership->roles()->where('slug', 'owner')->first()->business_id);

        // Owner grants all initial permissions.
        $owner = $business->roles()->where('slug', 'owner')->firstOrFail();
        foreach (config('permissions.groups') as $keys) {
            foreach ($keys as $key) {
                $this->assertTrue($owner->hasPermission($key), "owner must have $key");
            }
        }
    }

    // --- Verification item 4: role <-> permission mapping ------------------

    public function test_default_roles_map_to_permissions_correctly(): void
    {
        $user = $this->makeUser();
        [, $membership, $roles] = $this->provision($user, 'Map Co.', 'viewer');

        $this->assertTrue($roles['owner']->hasPermission('settings.manage'));
        $this->assertTrue($roles['admin']->hasPermission('settings.manage'));
        $this->assertTrue($roles['viewer']->hasPermission('users.view'));
        $this->assertTrue($roles['viewer']->hasPermission('settings.view'));
        $this->assertFalse($roles['viewer']->hasPermission('users.manage'));
        $this->assertFalse($roles['viewer']->hasPermission('settings.manage'));

        // Permissions are globally shared rows: the same permission id powers
        // the mapping across different businesses.
        $permId = Permission::where('name', 'settings.manage')->value('id');
        $ownerOther = $this->provision($this->makeUser(), 'Other Co.', 'owner');
        $this->assertSame($permId, $ownerOther[2]['owner']->permissions()->where('name', 'settings.manage')->first()->id);
    }

    // --- Verification item 5: membership receives role ---------------------

    public function test_membership_has_permission_through_its_roles(): void
    {
        $user = $this->makeUser();
        [, $ownerMembership] = $this->provision($user, 'Roles Co.', 'owner');
        [, $viewerMembership] = $this->provision($user, 'Views Co.', 'viewer');

        $this->assertTrue($ownerMembership->hasPermission('settings.manage'));
        $this->assertFalse($viewerMembership->hasPermission('settings.manage'));
        $this->assertTrue($viewerMembership->hasPermission('settings.view'));
    }

    // --- Verification items 6-7: permission middleware ---------------------

    public function test_permission_middleware_allows_authorized_and_returns_403_for_unauthorized_memberships(): void
    {
        $this->registerProtectedRoute('/__perm/settings-manage', 'settings.manage');
        $this->registerProtectedRoute('/__perm/users-view', 'users.view');

        $owner = $this->makeUser('Owner');
        $viewer = $this->makeUser('Viewer');
        $business = $this->makeBusiness('Mid Co.');
        $roles = $business->provisionDefaultRoles();
        $owner->memberships()->create(['business_id' => $business->id])->assignRole($roles['owner']);
        $viewer->memberships()->create(['business_id' => $business->id])->assignRole($roles['viewer']);

        $this->actingAs($owner)->withSession([$this->sessionKey() => $business->id])->get('/__perm/settings-manage')->assertOk()->assertSee('ALLOWED-settings.manage');
        $this->actingAs($viewer)->withSession([$this->sessionKey() => $business->id])->get('/__perm/settings-manage')->assertForbidden();
        $this->actingAs($viewer)->withSession([$this->sessionKey() => $business->id])->get('/__perm/users-view')->assertOk();
    }

    public function test_permission_middleware_requires_authentication_and_never_redirects_unauthorized_users(): void
    {
        $this->registerProtectedRoute('/__perm/settings-manage', 'settings.manage');

        // Guests are challenged to login (never evaluated).
        $this->get('/__perm/settings-manage')->assertRedirect(route('login'));

        // Authenticated but without a business: plain 403, no business switching.
        $user = $this->makeUser();
        $this->actingAs($user)->get('/__perm/settings-manage')->assertForbidden();
    }

    // --- Mandatory Scenario B: same user, two businesses, different roles ---

    public function test_same_user_two_businesses_switching_changes_effective_permissions(): void
    {
        $this->registerProtectedRoute('/__perm/settings-manage', 'settings.manage');

        $user = $this->makeUser('Multi');
        [$businessA] = $this->provision($user, 'Business A', 'admin');
        [, $membershipB] = $this->provision($user, 'Business B', 'viewer');

        // In Business A the Admin permission succeeds.
        $this->actingAs($user)->withSession([$this->sessionKey() => $businessA->id])->get('/__perm/settings-manage')->assertOk();
        $this->get('/app')->assertOk()->assertSee('Admin')->assertSee(__('authorization.permission_settings_manage'));

        // Switch to Business B: the same Admin-only permission now fails.
        $this->post(route('business.switch'), ['business_id' => $membershipB->business_id])->assertRedirect(route('app.home'));
        $this->assertSame($membershipB->business_id, session($this->sessionKey()));
        $this->get('/__perm/settings-manage')->assertForbidden();
        $this->get('/app')->assertOk()->assertSee('Viewer')->assertDontSee(__('authorization.permission_settings_manage'));

        // Switch back to Business A: the permission succeeds again.
        $this->post(route('business.switch'), ['business_id' => $businessA->id])->assertRedirect(route('app.home'));
        $this->get('/__perm/settings-manage')->assertOk();
        $this->get('/app')->assertOk()->assertSee('Admin')->assertSee(__('authorization.permission_settings_manage'));

        $this->assertSame($businessA->id, app(BusinessContext::class)->currentId());
    }

    // --- Mandatory Scenario A: isolation between User A and User B ----------

    public function test_cross_business_users_do_not_leak_authorization(): void
    {
        $this->registerProtectedRoute('/__perm/settings-manage', 'settings.manage');

        $userA = $this->makeUser('Alice');
        $userB = $this->makeUser('Bob');
        [$businessA] = $this->provision($userA, 'Business A', 'owner');
        [$businessB, $membershipB] = $this->provision($userB, 'Business B', 'viewer');

        // User A can settings.manage in Business A.
        $this->actingAs($userA)->withSession([$this->sessionKey() => $businessA->id])->get('/__perm/settings-manage')->assertOk();

        // User B cannot settings.manage in Business B.
        $this->actingAs($userB)->withSession([$this->sessionKey() => $businessB->id])->get('/__perm/settings-manage')->assertForbidden();

        // User B cannot reach Business A's role through the assignment API.
        $ownerRoleA = $businessA->roles()->where('slug', 'owner')->firstOrFail();
        try {
            $membershipB->assignRole($ownerRoleA);
            $this->fail('Cross-business role assignment must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }
        $this->assertDatabaseMissing('business_membership_role', ['membership_id' => $membershipB->id, 'role_id' => $ownerRoleA->id]);

        // User B cannot even buy access to Business A's role via raw pivot rows.
        $membershipB->roles()->attach($ownerRoleA->id);
        $this->assertFalse($membershipB->hasPermission('settings.manage'));

        // Expected authorization also holds via the direct service + Gate.
        $this->rebuildContext();
        $this->actingAs($userA);
        session([$this->sessionKey() => $businessA->id]);
        $this->rebuildContext();
        $this->assertTrue(app(MembershipAuthorization::class)->can('settings.manage', $userA, $businessA));
        $this->assertFalse(app(MembershipAuthorization::class)->can('settings.manage', $userA, $businessB));
        $this->assertFalse(app(MembershipAuthorization::class)->can('settings.manage', $userB, $businessA));
    }

    // --- Verification item 9 / 20: forged ids and stale selection ----------

    public function test_forged_business_id_cannot_grant_privileges(): void
    {
        $this->registerProtectedRoute('/__perm/settings-manage', 'settings.manage');

        $userA = $this->makeUser('Alice');   // owner of Business A
        $userB = $this->makeUser('Bob');     // viewer of Business B
        [$businessA] = $this->provision($userA, 'Business A', 'owner');
        [$businessB] = $this->provision($userB, 'Business B', 'viewer');

        // A forges the session to Business B: resolution falls back to A's own
        // business, and A's authority never reaches Business B.
        $this->actingAs($userA)->withSession([$this->sessionKey() => $businessB->id])->get('/__perm/settings-manage')->assertOk();
        $this->assertSame($businessA->id, session($this->sessionKey()));

        // B forges the session to Business A (not B's member): authorization is
        // still evaluated inside B's own business only -> denied.
        $this->actingAs($userB)->withSession([$this->sessionKey() => $businessA->id])->get('/__perm/settings-manage')->assertForbidden();
        $this->assertSame($businessB->id, session($this->sessionKey()));
    }

    public function test_forged_role_id_cannot_escalate_privileges_across_businesses(): void
    {
        $attacker = $this->makeUser('Attacker');
        $victim = $this->makeUser('Victim');
        [, $attackerMembership] = $this->provision($attacker, 'Attacker Co.', 'viewer');
        [$victimBusiness] = $this->provision($victim, 'Victim Co.', 'owner');

        // Attacker forges a pivot row granting themselves the victim's Owner
        // role. Defense-in-depth must neutralize it.
        $attackerMembership->roles()->attach($victimBusiness->roles()->where('slug', 'owner')->firstOrFail()->id);

        $this->assertTrue($attackerMembership->hasPermission('users.view'));
        $this->assertFalse($attackerMembership->hasPermission('settings.manage'));
        $this->assertFalse($attackerMembership->hasPermission('users.manage'));

        // The attacker's membership was never granted the foreign role for
        // real: its own viewer role still is the only source of permission.
        $this->assertSame(['viewer'], $attackerMembership->roles()->where('roles.business_id', $attackerMembership->business_id)->pluck('slug')->all());
    }

    // --- Verification item 8: cross-business role assignment guard ----------

    public function test_duplicate_role_assignment_is_avoided(): void
    {
        $user = $this->makeUser();
        [$business, $membership, $roles] = $this->provision($user, 'Dupe Co.', 'admin');

        $membership->assignRole($roles['admin']);
        $membership->assignRole($roles['admin']);
        $this->assertDatabaseCount('business_membership_role', 1);

        $membership->assignRole($roles['viewer']);
        $this->assertDatabaseCount('business_membership_role', 2);
        $this->assertSame(['admin', 'viewer'], $membership->roles()->orderBy('roles.id')->pluck('slug')->all());
    }

    // --- Verification item 10: current business controls permission ---------

    public function test_current_business_determines_permission_result(): void
    {
        $this->registerProtectedRoute('/__perm/settings-manage', 'settings.manage');

        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'A Co.', 'owner');
        [$businessB] = $this->provision($user, 'B Co.', 'viewer');

        $this->assertTrue($user->businesses()->whereKey($businessA->id)->exists());
        $this->assertTrue($user->businesses()->whereKey($businessB->id)->exists());

        $this->actingAs($user)->withSession([$this->sessionKey() => $businessA->id])->get('/__perm/settings-manage')->assertOk();
        $this->post(route('business.switch'), ['business_id' => $businessB->id])->assertRedirect(route('app.home'));
        $this->get('/__perm/settings-manage')->assertForbidden();
    }

    // --- Gate / Blade / service convention ---------------------------------

    public function test_gate_and_service_agree_and_deny_without_business_context(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Gate Co.', 'owner');

        $this->rebuildContext();
        $this->actingAs($user);
        session([$this->sessionKey() => $business->id]);
        $this->rebuildContext();

        $this->assertTrue(Gate::allows('settings.manage'));
        $this->assertTrue(Gate::allows('users.view'));
        $this->assertTrue(app(MembershipAuthorization::class)->can('settings.manage'));

        // Abilities that are not permission keys fall through to false.
        $this->assertFalse(Gate::allows('nonexistent.ability'));

        // No business context (authenticated user, no membership): deny.
        $lonely = $this->makeUser('Lonely');
        $this->rebuildContext();
        $this->actingAs($lonely);
        $this->rebuildContext();
        $this->assertFalse(Gate::allows('settings.manage'));
        $this->assertFalse(app(MembershipAuthorization::class)->can('settings.manage'));
    }

    // --- Verification item 15-16: no Batch 10+ authorization scope ----------

    public function test_permission_catalogue_is_minimal_and_no_batch10_authorization_scope_exists(): void
    {
        $this->registerProtectedRoute('/__perm/settings-manage', 'settings.manage');

        // Batch 11 added catalog permissions (categories/units/taxes), Batch 12
        // products, Batch 14 quotations, Batch 15 invoices, Batch 16 payments,
        // Batch 17 expenses, and Batch 23 reports — 24 keys total.
        $expected = [
            'users.view', 'users.manage',
            'settings.view', 'settings.manage',
            'customers.view', 'customers.manage',
            'categories.view', 'categories.manage',
            'units.view', 'units.manage',
            'taxes.view', 'taxes.manage',
            'products.view', 'products.manage',
            'quotations.view', 'quotations.manage',
            'invoices.view', 'invoices.manage',
            'payments.view', 'payments.create', 'payments.reverse',
            'expenses.view', 'expenses.manage',
            'reports.view',
        ];
        $this->assertSame($expected, Permission::orderBy('id')->pluck('name')->all());
        $this->assertFalse(Schema::hasColumn('permissions', 'business_id'));
        $this->assertFalse(Schema::hasTable('staff'));

        // No staff/user-management or module-management routes exist.
        foreach (['users.index', 'users.create', 'staff.index', 'manage.roles', 'module.*'] as $pattern) {
            $this->assertCount(0, collect(Route::getRoutes()->getRoutesByName())->filter(function ($route, $name) use ($pattern) {
                return fnmatch($pattern, (string) $name);
            })->all(), "Unexpected route matching $pattern");
        }

        // The middleware still guards the demo route for a viewer.
        $viewer = $this->makeUser();
        [$business] = $this->provision($viewer, 'Scope Co.', 'viewer');
        $this->actingAs($viewer)->withSession([$this->sessionKey() => $business->id])->get('/__perm/settings-manage')->assertForbidden();
    }
}
