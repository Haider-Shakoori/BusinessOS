<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\FieldPulseIntegration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\BusinessSettings;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SettingsTest extends TestCase
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

    private function makeUser(string $name = 'Settings User'): User
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

    private function sessionKey(): string
    {
        return config('business.context.session_key');
    }

    /**
     * Re-create the request-lifecycle context so direct service assertions
     * observe the currently acting user and the stored current business.
     */
    private function rebuildContext(): void
    {
        $this->app->forgetScopedInstances();
    }

    /**
     * Acting as the user with the given business selected, then refresh the
     * request-lifecycle context so a freshly resolved BusinessSettings reads
     * the right business.
     */
    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([$this->sessionKey() => $business->id]);
        $this->rebuildContext();
    }

    // --- Schema + config defaults ------------------------------------------

    public function test_settings_schema_and_defaults_are_in_place_without_rows(): void
    {
        $this->assertTrue(Schema::hasTable('settings'));
        $this->assertTrue(Schema::hasColumn('settings', 'business_id'));
        $this->assertTrue(Schema::hasColumn('settings', 'group'));
        $this->assertTrue(Schema::hasColumn('settings', 'key'));
        $this->assertTrue(Schema::hasColumn('settings', 'value'));
        $this->assertTrue(Schema::hasColumn('settings', 'type'));

        $this->assertSame('UTC', config('settings.definitions.regional.timezone')['default']);
        $this->assertSame('Y-m-d', config('settings.definitions.regional.date_format')['default']);
        $this->assertSame('H:i', config('settings.definitions.regional.time_format')['default']);
        $this->assertNull(config('settings.definitions.regional.locale')['default']);

        // Sparse overrides: no business == zero rows.
        $this->assertDatabaseCount('settings', 0);
    }

    // --- Service: defaults, overrides, unsupported keys --------------------

    public function test_service_resolves_defaults_persists_overrides_and_rejects_unknown_keys(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Service Co.', 'owner');

        $this->actIn($user, $business);
        $settings = app(BusinessSettings::class);

        $this->assertSame('UTC', $settings->get('regional.timezone'));
        $this->assertSame('Y-m-d', $settings->get('regional.date_format'));
        $this->assertNull($settings->get('regional.locale'));
        $this->assertNull($settings->get('general.address', 'fallback'));
        $this->assertFalse($settings->has('regional.timezone'));
        $this->assertDatabaseCount('settings', 0);

        $settings->set('regional.timezone', 'Asia/Kabul');
        $settings->set('regional.date_format', 'd/m/Y');

        $this->assertSame('Asia/Kabul', $settings->get('regional.timezone'));
        $this->assertSame('d/m/Y', $settings->get('regional.date_format'));
        $this->assertTrue($settings->has('regional.timezone'));
        $this->assertSame('Y-m-d', config('settings.definitions.regional.date_format')['default']);
        $this->assertDatabaseCount('settings', 2);
        $this->assertDatabaseHas('settings', ['business_id' => $business->id, 'group' => 'regional', 'key' => 'timezone', 'value' => 'Asia/Kabul']);

        // Unknown keys never persist and resolve to the caller default.
        $this->assertSame('OPEN', $settings->get('orders.status', 'OPEN'));
        $settings->set('orders.status', 'CLOSED');
        $this->assertDatabaseCount('settings', 2);
        $this->assertDatabaseMissing('settings', ['group' => 'orders', 'key' => 'status']);
    }

    public function test_group_and_all_return_scoped_values(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Group Co.', 'owner');
        $this->actIn($user, $business);

        app(BusinessSettings::class)->set('regional.timezone', 'Asia/Tehran');

        $group = app(BusinessSettings::class)->group('regional');
        $this->assertSame('Asia/Tehran', $group['timezone']);
        $this->assertArrayHasKey('date_format', $group);

        $all = app(BusinessSettings::class)->all();
        $this->assertSame('Asia/Tehran', $all['regional.timezone']);
        $this->assertArrayHasKey('general.address', $all);
    }

    // --- Route guarding: auth + business + module + permission ---------------

    public function test_settings_page_requires_authentication_and_a_business(): void
    {
        $this->get('/settings')->assertRedirect(route('login'));

        // Authenticated but with no business: onboarding state, no settings page.
        $user = $this->makeUser();
        $this->actingAs($user)->get('/settings')->assertRedirect(route('business.create'));
    }

    public function test_settings_page_requires_enabled_settings_module(): void
    {
        $user = $this->makeUser('Mod Owner');
        [$business, , $roles] = $this->provision($user, 'Mod Co.', 'owner');

        $this->actIn($user, $business);
        $this->get('/settings')->assertOk()->assertSee(__('settings.general'));

        // Disable the module: the same owner is now refused outright.
        $business->modules()->where('module_key', 'settings')->update(['enabled' => false]);
        $this->get('/settings')->assertForbidden();

        // Re-enable restores access for the owner.
        $business->modules()->where('module_key', 'settings')->update(['enabled' => true]);
        $this->get('/settings')->assertOk();
    }

    public function test_settings_page_requires_settings_view_permission(): void
    {
        $owner = $this->makeUser('View Owner');
        [$ownerBusiness] = $this->provision($owner, 'View Co.', 'owner');

        // A member with NO role has no permissions at all: view is denied.
        $outsider = $this->makeUser('No Role');
        $business = $this->makeBusiness('Solo Co.');
        $business->provisionDefaultModules();
        $outsider->memberships()->create(['business_id' => $business->id]);

        $this->actIn($owner, $ownerBusiness);
        $this->get('/settings')->assertOk()->assertSee(__('settings.general'));

        $this->actIn($outsider, $business);
        $this->get('/settings')->assertForbidden();
    }

    // --- Mandatory authorization scenario: view without manage --------------

    public function test_viewer_can_view_settings_but_cannot_update_until_permission_is_granted(): void
    {
        $user = $this->makeUser('Viewer');
        [$business, , $roles] = $this->provision($user, 'Authz Co.', 'viewer');

        $this->actIn($user, $business);
        $this->assertFalse(Gate::allows('settings.manage'));

        // Viewer (settings.view only) may VIEW the page and sees the read-only notice.
        $this->get('/settings')
            ->assertOk()
            ->assertSee(__('settings.view_only'))
            ->assertSee(__('settings.general'));

        // The same viewer cannot persist anything: denied before validation.
        $this->patch('/settings', [
            'name' => 'Hijacked Name',
            'general.address' => 'Sneaky Avenue 1',
            'regional.timezone' => 'Asia/Kabul',
            'regional.date_format' => 'Y-m-d',
            'regional.time_format' => 'H:i',
            'regional.locale' => 'en',
        ])->assertForbidden();

        $this->assertDatabaseCount('settings', 0);
        $this->assertSame('Authz Co.', $business->fresh()->name);

        // Grant the manage permission: the same payload now succeeds.
        $permissionId = Permission::where('name', 'settings.manage')->value('id');
        $roles['viewer']->permissions()->syncWithoutDetaching([$permissionId]);
        $this->rebuildContext();

        $this->patch('/settings', [
            'name' => 'Authz Co. Naming',
            'regional.timezone' => 'Asia/Kabul',
            'regional.date_format' => 'Y-m-d',
            'regional.time_format' => 'H:i',
            'regional.locale' => 'fa',
        ])->assertRedirect(route('settings.index'))->assertSessionHas('status', __('settings.saved'));

        $this->assertSame('Authz Co. Naming', $business->fresh()->name);
        $this->assertDatabaseHas('settings', ['business_id' => $business->id, 'group' => 'regional', 'key' => 'timezone', 'value' => 'Asia/Kabul']);
        $this->assertDatabaseHas('settings', ['business_id' => $business->id, 'group' => 'regional', 'key' => 'locale', 'value' => 'fa']);
    }

    // --- Whitelist validation ------------------------------------------------

    public function test_settings_validation_rejects_invalid_t_values(): void
    {
        $user = $this->makeUser('Valid Owner');
        [$business] = $this->provision($user, 'Valid Co.', 'owner');

        $this->actIn($user, $business);

        $this->patch('/settings', ['regional.timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors(['regional.timezone' => __('settings.validation.timezone_invalid')]);

        $this->patch('/settings', ['regional.date_format' => 'DD/MM/YYYY'])
            ->assertSessionHasErrors(['regional.date_format' => __('settings.validation.date_format_invalid')]);

        $this->patch('/settings', ['regional.locale' => 'de'])
            ->assertSessionHasErrors(['regional.locale' => __('settings.validation.locale_invalid')]);

        $this->patch('/settings', ['general.email' => 'not-an-email'])
            ->assertSessionHasErrors(['general.email' => __('settings.validation.email_invalid')]);

        $this->patch('/settings', ['name' => str_repeat('a', 256)])
            ->assertSessionHasErrors(['name' => __('settings.validation.name_max')]);

        // None of the rejected payloads wrote anything.
        $this->assertDatabaseCount('settings', 0);
    }

    // --- Isolation: forged identifiers and unknown keys are ignored ----------

    public function test_update_ignores_forged_business_id_and_unknown_keys(): void
    {
        $user = $this->makeUser('Forgery Owner');
        [$business] = $this->provision($user, 'Forgery Co.', 'owner');
        $otherBusiness = $this->makeBusiness('Victim Co.');

        $this->actIn($user, $business);

        $this->patch('/settings', [
            'name' => 'Forgery Co. Updated',
            'general.address' => 'Innocent Street 10',
            'regional.timezone' => 'Asia/Kabul',
            'regional.locale' => 'fa',
            // Forgery attempts that must be silently ignored.
            'business_id' => $otherBusiness->id,
            'orders.status' => 'CLOSED',
            'superadmin' => 'yes',
        ])->assertRedirect(route('settings.index'))->assertSessionHas('status', __('settings.saved'));

        $this->assertDatabaseHas('settings', ['business_id' => $business->id, 'group' => 'regional', 'key' => 'timezone', 'value' => 'Asia/Kabul']);
        $this->assertDatabaseMissing('settings', ['business_id' => $otherBusiness->id]);
        $this->assertDatabaseMissing('settings', ['group' => 'orders', 'key' => 'status']);
        $this->assertDatabaseMissing('settings', ['group' => 'superadmin']);
    }

    // --- Mandatory multi-business scenario: read + write isolation -----------

    public function test_mandatory_multi_business_settings_isolation_and_switch_reflection(): void
    {
        $user = $this->makeUser('Multi Owner');
        [$businessA] = $this->provision($user, 'Alpha Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Co.', 'owner');

        // Business A: configure its own values.
        $this->actIn($user, $businessA);
        $this->patch('/settings', [
            'name' => 'Alpha Co.',
            'regional.timezone' => 'Asia/Kabul',
            'regional.date_format' => 'Y-m-d',
            'regional.time_format' => 'H:i',
            'regional.locale' => 'fa',
        ])->assertRedirect(route('settings.index'));

        $this->assertDatabaseHas('settings', ['business_id' => $businessA->id, 'group' => 'regional', 'key' => 'timezone', 'value' => 'Asia/Kabul']);

        $this->get('/settings')
            ->assertOk()
            ->assertSee('Alpha Co.')
            ->assertSee('value="Asia/Kabul" selected', false)
            ->assertSee('value="Y-m-d" selected', false);

        // Switch to B: the page shows B's untouched defaults, never A's state.
        $this->post(route('business.switch'), ['business_id' => $businessB->id])->assertRedirect(route('app.home'));
        $this->get('/settings')
            ->assertOk()
            ->assertSee('Beta Co.')
            ->assertSee('value="UTC" selected', false)
            ->assertDontSee('value="Asia/Kabul" selected', false)
            ->assertSee('value="Y-m-d" selected', false);

        // Business B: configure different values.
        $this->patch('/settings', [
            'name' => 'Beta Co.',
            'regional.timezone' => 'UTC',
            'regional.date_format' => 'd/m/Y',
            'regional.time_format' => 'H:i',
            'regional.locale' => 'en',
        ])->assertRedirect(route('settings.index'));

        $this->get('/settings')
            ->assertOk()
            ->assertSee('Beta Co.')
            ->assertSee('value="UTC" selected', false)
            ->assertSee('value="d/m/Y" selected', false)
            ->assertDontSee('value="Asia/Kabul" selected', false);

        // Back to A: everything A configured earlier is still intact and B's
        // overrides never leaked in.
        $this->post(route('business.switch'), ['business_id' => $businessA->id])->assertRedirect(route('app.home'));
        $this->get('/settings')
            ->assertOk()
            ->assertSee('Alpha Co.')
            ->assertSee('value="Asia/Kabul" selected', false)
            ->assertSee('value="Y-m-d" selected', false)
            ->assertDontSee('value="d/m/Y" selected', false);

        // The business names were updated on the businesses table only.
        $this->assertSame('Alpha Co.', $businessA->fresh()->name);
        $this->assertSame('Beta Co.', $businessB->fresh()->name);

        // Database rows are perfectly scoped per business.
        $this->assertDatabaseHas('settings', ['business_id' => $businessA->id, 'group' => 'regional', 'key' => 'timezone', 'value' => 'Asia/Kabul']);
        $this->assertDatabaseHas('settings', ['business_id' => $businessB->id, 'group' => 'regional', 'key' => 'timezone', 'value' => 'UTC']);
        $this->assertDatabaseHas('settings', ['business_id' => $businessB->id, 'group' => 'regional', 'key' => 'date_format', 'value' => 'd/m/Y']);
        $this->assertDatabaseMissing('settings', ['business_id' => $businessA->id, 'group' => 'regional', 'key' => 'date_format', 'value' => 'd/m/Y']);

        // Service agrees with the rendered page for both businesses.
        $this->actIn($user, $businessA);
        $this->assertSame('Asia/Kabul', app(BusinessSettings::class)->get('regional.timezone'));
        $this->actIn($user, $businessB);
        $this->assertSame('UTC', app(BusinessSettings::class)->get('regional.timezone'));
        $this->assertSame('d/m/Y', app(BusinessSettings::class)->get('regional.date_format'));
    }

    // --- Business name lives on the businesses table ------------------------

    public function test_business_name_update_never_writes_settings_rows(): void
    {
        $user = $this->makeUser('Name Owner');
        [$business] = $this->provision($user, 'Name Co.', 'owner');

        $this->actIn($user, $business);
        $this->patch('/settings', ['name' => 'Renamed Co.'])
            ->assertRedirect(route('settings.index'));

        $this->assertSame('Renamed Co.', $business->fresh()->name);
        $this->assertDatabaseCount('settings', 0);
        $this->get('/settings')->assertOk()->assertSee('Renamed Co.');
    }

    // --- Locale precedence: session -> business default -> app default ------

    public function test_locale_precedence_is_session_then_business_then_app(): void
    {
        $user = $this->makeUser('Locale Owner');
        [$business] = $this->provision($user, 'Locale Co.', 'owner');

        $this->actIn($user, $business);
        app(BusinessSettings::class)->set('regional.locale', 'fa');
        $this->rebuildContext();

        // No session locale, business default is fa -> fa.
        $this->get('/app')->assertOk();
        $this->assertSame('fa', app()->getLocale());

        // An explicit session selection always wins over the business default.
        $this->withSession(['locale' => 'en'])->get('/app')->assertOk();
        $this->assertSame('en', app()->getLocale());

        // No session selection and no business default -> application default.
        app(BusinessSettings::class)->set('regional.locale', null);
        session()->forget('locale');
        $this->rebuildContext();
        $this->get('/app')->assertOk();
        $this->assertSame(config('app.locale'), app()->getLocale());
    }

    public function test_fieldpulse_integration_settings_are_business_scoped_and_require_a_mapping_key(): void
    {
        $user = $this->makeUser('Integration Owner');
        [$businessA] = $this->provision($user, 'Integration A', 'owner');
        [$businessB] = $this->provision($user, 'Integration B', 'owner');

        $this->actIn($user, $businessA);

        $this->patch('/settings', [
            'fieldpulse.enabled' => '1',
            'fieldpulse.organization_key' => '',
        ])->assertSessionHasErrors(['fieldpulse.organization_key']);

        $this->patch('/settings', [
            'fieldpulse.enabled' => '1',
            'fieldpulse.organization_key' => 'business-a-fieldpulse',
        ])->assertRedirect(route('settings.index'));

        $integrationA = FieldPulseIntegration::query()
            ->where('business_id', $businessA->id)
            ->firstOrFail();
        $this->assertTrue($integrationA->enabled);
        $this->assertSame(
            'business-a-fieldpulse',
            $integrationA->organization_key,
        );

        $this->actIn($user, $businessB);
        $this->get('/settings')
            ->assertOk()
            ->assertSee('FieldPulse integration')
            ->assertDontSee('business-a-fieldpulse');

        $this->patch('/settings', [
            'fieldpulse.enabled' => '1',
            'fieldpulse.organization_key' => 'business-a-fieldpulse',
        ])->assertSessionHasErrors(['fieldpulse.organization_key']);

        $this->patch('/settings', [
            'fieldpulse.enabled' => '1',
            'fieldpulse.organization_key' => 'business-b-fieldpulse',
        ])->assertRedirect(route('settings.index'));

        $this->assertDatabaseHas('fieldpulse_integrations', [
            'business_id' => $businessA->id,
            'organization_key' => 'business-a-fieldpulse',
            'enabled' => 1,
        ]);
        $this->assertDatabaseHas('fieldpulse_integrations', [
            'business_id' => $businessB->id,
            'organization_key' => 'business-b-fieldpulse',
            'enabled' => 1,
        ]);
    }

}
