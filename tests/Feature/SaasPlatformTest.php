<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessSubscription;
use App\Models\Product;
use App\Models\SaasPlan;
use App\Models\User;
use App\Services\SaasUsageService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaasPlatformTest extends TestCase
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
    }

    private function user(bool $superAdmin = false): User
    {
        $user = User::create([
            'name' => $superAdmin ? 'Platform Admin' : 'Business Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);

        if ($superAdmin) {
            $user->forceFill(['is_super_admin' => true])->save();
        }

        return $user;
    }

    private function business(User $user, string $name = 'SaaS Business'): Business
    {
        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        return $business;
    }

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();
    }

    public function test_saas_schema_and_super_admin_command_are_available(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'is_super_admin'));
        $this->assertTrue(Schema::hasTable('saas_plans'));
        $this->assertTrue(Schema::hasTable('business_subscriptions'));

        $user = $this->user();

        $this->artisan('businessos:super-admin', ['email' => $user->email])
            ->assertExitCode(0);

        $this->assertTrue($user->fresh()->is_super_admin);

        $this->artisan('businessos:super-admin', [
            'email' => $user->email,
            '--revoke' => true,
        ])->assertExitCode(0);

        $this->assertFalse($user->fresh()->is_super_admin);
    }

    public function test_only_super_admin_can_open_platform_console_and_manage_plan_subscription(): void
    {
        $owner = $this->user();
        $business = $this->business($owner);

        $this->actingAs($owner)
            ->get('/platform/saas')
            ->assertForbidden();

        $admin = $this->user(true);
        $this->flushSession();
        $this->actingAs($admin)
            ->get('/platform/saas')
            ->assertOk()
            ->assertSee('SaaS Platform');

        $this->post('/platform/saas/plans', [
            'key' => 'business',
            'name' => 'Business',
            'description' => 'Business plan',
            'monthly_price' => '1000',
            'annual_price' => '10000',
            'currency_code' => 'AFN',
            'limit_members' => 10,
            'limit_products' => 100,
            'limit_enabled_modules' => 20,
            'features' => "Inventory\nAccounting",
            'is_active' => '1',
            'is_default' => '1',
            'sort_order' => 10,
        ])->assertRedirect();

        $plan = SaasPlan::query()->firstOrFail();

        $this->patch('/platform/saas/businesses/'.$business->id.'/subscription', [
            'saas_plan_id' => $plan->id,
            'status' => 'active',
        ])->assertRedirect();

        $this->assertDatabaseHas('business_subscriptions', [
            'business_id' => $business->id,
            'saas_plan_id' => $plan->id,
            'status' => 'active',
        ]);
    }

    public function test_suspended_or_expired_tenant_is_blocked_but_platform_admin_is_not(): void
    {
        $owner = $this->user();
        $business = $this->business($owner);
        $plan = SaasPlan::create([
            'key' => 'limited',
            'name' => 'Limited',
            'currency_code' => 'AFN',
            'is_active' => true,
        ]);

        BusinessSubscription::create([
            'business_id' => $business->id,
            'saas_plan_id' => $plan->id,
            'status' => 'suspended',
        ]);

        $this->actIn($owner, $business);
        $this->get('/app')->assertForbidden();

        $owner->forceFill(['is_super_admin' => true])->save();
        $this->actIn($owner->fresh(), $business);
        $this->get('/app')->assertOk();

        $owner->forceFill(['is_super_admin' => false])->save();
        $business->subscription()->update([
            'status' => 'trialing',
            'trial_ends_at' => now()->subMinute(),
        ]);

        $this->actIn($owner->fresh(), $business);
        $this->get('/app')->assertForbidden();
    }

    public function test_usage_limits_count_members_products_and_enabled_modules(): void
    {
        $owner = $this->user();
        $business = $this->business($owner);

        $plan = SaasPlan::create([
            'key' => 'capped',
            'name' => 'Capped',
            'currency_code' => 'AFN',
            'limits' => [
                'members' => 1,
                'products' => 1,
                'enabled_modules' => $business->modules()->where('enabled', true)->count(),
            ],
            'is_active' => true,
        ]);

        BusinessSubscription::create([
            'business_id' => $business->id,
            'saas_plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $usage = app(SaasUsageService::class);

        $this->assertFalse($usage->canAdd($business, 'members'));
        $this->assertTrue($usage->canAdd($business, 'products'));

        DB::table('products')->insert([
            'business_id' => $business->id,
            'type' => 'product',
            'name' => 'Capped Product',
            'sale_price' => '10.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse($usage->canAdd($business, 'products'));
        $this->assertFalse($usage->canAdd($business, 'enabled_modules'));
    }

    public function test_new_business_can_be_attached_to_default_plan(): void
    {
        $plan = SaasPlan::create([
            'key' => 'default',
            'name' => 'Default Plan',
            'currency_code' => 'AFN',
            'is_active' => true,
            'is_default' => true,
        ]);
        $business = Business::create(['name' => 'Provisioned']);

        $subscription = app(SaasUsageService::class)
            ->provisionDefaultSubscription($business);

        $this->assertNotNull($subscription);
        $this->assertSame($plan->id, $subscription->saas_plan_id);
        $this->assertSame('active', $subscription->status);
    }
}
