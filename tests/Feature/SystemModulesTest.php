<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\BusinessNotification;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemModulesTest extends TestCase
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

    private function user(string $name = 'System Owner'): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function business(User $user): Business
    {
        $business = Business::create(['name' => 'System Business']);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (array_keys(config('modules.registry')) as $module) {
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

    public function test_system_module_schema_and_owner_routes_are_available(): void
    {
        $this->assertTrue(Schema::hasTable('activity_logs'));
        $this->assertTrue(Schema::hasTable('business_notifications'));

        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $this->get(route('system.users-roles.index'))->assertOk();
        $this->get(route('system.modules.index'))->assertOk();
        $this->get(route('system.activity.index'))->assertOk();
        $this->get(route('system.notifications.index'))->assertOk();
        $this->get(route('system.search.index'))->assertOk();
    }

    public function test_member_creation_assigns_role_notifies_members_and_is_audited(): void
    {
        $owner = $this->user();
        $business = $this->business($owner);
        $this->actIn($owner, $business);

        $viewer = Role::query()
            ->where('business_id', $business->id)
            ->where('slug', 'viewer')
            ->firstOrFail();

        $this->post(route('system.users-roles.members.store'), [
            'name' => 'New Member',
            'email' => 'new.member@example.test',
            'password' => 'StrongPassword123!',
            'role_id' => $viewer->id,
        ])->assertRedirect();

        $newUser = User::query()->where('email', 'new.member@example.test')->firstOrFail();
        $membership = BusinessMembership::query()
            ->where('business_id', $business->id)
            ->where('user_id', $newUser->id)
            ->firstOrFail();

        $this->assertTrue($membership->roles()->whereKey($viewer->id)->exists());

        $this->assertDatabaseHas('business_notifications', [
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'type' => 'member',
        ]);

        $this->assertDatabaseHas('business_notifications', [
            'business_id' => $business->id,
            'user_id' => $newUser->id,
            'type' => 'member',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'route_name' => 'system.users-roles.members.store',
            'event' => 'created',
        ]);
    }

    public function test_last_owner_cannot_be_demoted(): void
    {
        $owner = $this->user();
        $business = $this->business($owner);
        $this->actIn($owner, $business);

        $membership = BusinessMembership::query()
            ->where('business_id', $business->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();

        $viewer = Role::query()
            ->where('business_id', $business->id)
            ->where('slug', 'viewer')
            ->firstOrFail();

        $this->patch(route('system.users-roles.members.update', $membership), [
            'role_id' => $viewer->id,
        ])->assertSessionHasErrors('role_id');

        $membership->refresh();
        $this->assertTrue($membership->roles()->where('slug', 'owner')->exists());
    }

    public function test_module_toggle_updates_business_module_and_emits_notification(): void
    {
        $owner = $this->user();
        $business = $this->business($owner);
        $this->actIn($owner, $business);

        $this->post(route('system.modules.toggle'), [
            'module_key' => 'crm',
            'enabled' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('business_modules', [
            'business_id' => $business->id,
            'module_key' => 'crm',
            'enabled' => 0,
        ]);

        $this->assertDatabaseHas('business_notifications', [
            'business_id' => $business->id,
            'user_id' => $owner->id,
            'type' => 'module',
        ]);

        $this->post(route('system.modules.toggle'), [
            'module_key' => 'dashboard',
            'enabled' => false,
        ])->assertSessionHasErrors('module_key');

        $this->assertDatabaseHas('business_modules', [
            'business_id' => $business->id,
            'module_key' => 'dashboard',
            'enabled' => 1,
        ]);
    }

    public function test_notification_read_state_is_per_user(): void
    {
        $owner = $this->user();
        $business = $this->business($owner);

        $other = $this->user('Second Member');
        $roles = $business->provisionDefaultRoles();
        $otherMembership = $other->memberships()->create(['business_id' => $business->id]);
        $otherMembership->assignRole($roles['viewer']);

        $this->actIn($owner, $business);

        $this->post(route('system.modules.toggle'), [
            'module_key' => 'crm',
            'enabled' => false,
        ])->assertRedirect();

        $ownerNotification = BusinessNotification::query()
            ->where('user_id', $owner->id)
            ->latest('id')
            ->firstOrFail();

        $this->post(route('system.notifications.mark-read', $ownerNotification))->assertRedirect();

        $this->assertNotNull($ownerNotification->fresh()->read_at);

        $otherNotification = BusinessNotification::query()
            ->where('user_id', $other->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertNull($otherNotification->read_at);
    }

    public function test_global_search_respects_module_enablement(): void
    {
        $owner = $this->user();
        $business = $this->business($owner);
        $this->actIn($owner, $business);

        Customer::create([
            'name' => 'Searchable Customer',
            'opening_balance' => 0,
        ]);

        $this->get(route('system.search.index', ['q' => 'Searchable']))
            ->assertOk()
            ->assertSee('Searchable Customer');

        BusinessModule::query()
            ->where('business_id', $business->id)
            ->where('module_key', 'customers')
            ->update(['enabled' => false]);

        $this->app->forgetScopedInstances();

        $this->get(route('system.search.index', ['q' => 'Searchable']))
            ->assertOk()
            ->assertDontSee('Searchable Customer');
    }

    public function test_viewer_has_search_and_notifications_but_not_system_administration(): void
    {
        $owner = $this->user();
        $business = $this->business($owner);
        $viewer = $this->user('Viewer');
        $roles = $business->provisionDefaultRoles();
        $membership = $viewer->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['viewer']);

        $this->actIn($viewer, $business);

        $this->get(route('system.search.index'))->assertOk();
        $this->get(route('system.notifications.index'))->assertOk();
        $this->get(route('system.users-roles.index'))->assertOk();
        $this->get(route('system.activity.index'))->assertForbidden();
        $this->get(route('system.modules.index'))->assertForbidden();
    }
}
