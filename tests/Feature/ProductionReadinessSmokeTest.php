<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionReadinessSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
        $this->seed(CurrencySeeder::class);
    }

    public function test_owner_can_open_every_primary_businessos_workspace(): void
    {
        [$user, $business] = $this->ownerWorkspace();
        $this->actIn($user, $business);

        $routes = [
            'app.home',
            'customers.index',
            'quotations.index',
            'invoices.index',
            'payments.index',
            'expenses.index',
            'products.index',
            'inventory.index',
            'purchasing.index',
            'pos.index',
            'accounting.index',
            'manufacturing.index',
            'crm.index',
            'hr.index',
            'reports.index',
            'system.notifications.index',
            'system.search.index',
            'system.activity.index',
            'system.assistant.index',
            'system.businesses.index',
            'system.users-roles.index',
            'system.modules.index',
            'settings.index',
            'settings.attendance-devices.index',
        ];

        foreach ($routes as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertDontSee('workspace.placeholder', false)
                ->assertDontSee('Server Error');
        }
    }

    public function test_primary_shell_renders_correct_direction_in_all_supported_languages(): void
    {
        [$user, $business] = $this->ownerWorkspace();

        foreach ([
            'en' => 'ltr',
            'fa' => 'rtl',
            'ar' => 'rtl',
        ] as $locale => $direction) {
            $this->actIn($user, $business);

            $this->withSession(['locale' => $locale])
                ->get(route('app.home'))
                ->assertOk()
                ->assertSee('lang="'.$locale.'"', false)
                ->assertSee('dir="'.$direction.'"', false);
        }
    }

    public function test_navigation_routes_resolve_to_real_implemented_workspaces(): void
    {
        [$user, $business] = $this->ownerWorkspace();
        $this->actIn($user, $business);

        foreach (config('navigation.items', []) as $item) {
            $route = $item['route'] ?? null;

            if (! is_string($route) || $route === '') {
                continue;
            }

            $this->assertTrue(
                app('router')->has($route),
                'Missing navigation route: '.$route,
            );

            $this->get(route($route))
                ->assertOk()
                ->assertDontSee('Coming soon')
                ->assertDontSee('workspace.placeholder', false);
        }
    }

    /**
     * @return array{User, Business}
     */
    private function ownerWorkspace(): array
    {
        $user = User::create([
            'name' => 'Production QA Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create([
            'name' => 'Production QA Business',
        ]);

        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();

        $membership = $user->memberships()->create([
            'business_id' => $business->id,
        ]);
        $membership->assignRole($roles['owner']);

        foreach (array_keys(config('modules.registry', [])) as $moduleKey) {
            BusinessModule::updateOrCreate(
                [
                    'business_id' => $business->id,
                    'module_key' => $moduleKey,
                ],
                ['enabled' => true],
            );
        }

        return [$user, $business];
    }

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();
    }
}
