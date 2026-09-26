<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Product;
use App\Models\Role;
use App\Models\SmartAssistantMessage;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssistantBusinessesTest extends TestCase
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

    private function user(string $name = 'Owner'): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function business(User $user, string $name = 'Business'): Business
    {
        $business = Business::create(['name' => $name]);
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

    public function test_assistant_schema_routes_and_navigation_are_real(): void
    {
        $this->assertTrue(Schema::hasTable('smart_assistant_messages'));

        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $this->get(route('system.assistant.index'))->assertOk();
        $this->get(route('system.businesses.index'))->assertOk();

        $items = collect(config('navigation.items'))->keyBy('key');
        $this->assertSame('system.assistant.index', $items['smart-assistant']['route']);
        $this->assertSame('system.businesses.index', $items['saas-businesses']['route']);
        $this->assertArrayNotHasKey('placeholder', $items['smart-assistant']);
        $this->assertArrayNotHasKey('placeholder', $items['saas-businesses']);
    }

    public function test_assistant_answers_live_inventory_metrics_and_saves_history(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $warehouse = Warehouse::create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'is_active' => true,
        ]);

        $product = Product::create([
            'type' => 'product',
            'name' => 'Widget',
            'sku' => 'W-001',
            'sale_price' => '10.0000',
        ]);

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '25.0000',
            'occurred_at' => now(),
        ]);

        $this->post(route('system.assistant.ask'), [
            'question' => 'What is our current stock position?',
        ])->assertRedirect();

        $messages = SmartAssistantMessage::query()->orderBy('id')->get();

        $this->assertCount(2, $messages);
        $this->assertSame('user', $messages[0]->role);
        $this->assertSame('assistant', $messages[1]->role);
        $this->assertStringContainsString('25.00', $messages[1]->content);
        $this->assertSame('inventory', $messages[1]->meta['topic']);
        $this->assertSame(25.0, (float) $messages[1]->meta['facts']['stock_quantity']);
    }

    public function test_assistant_does_not_leak_metrics_without_underlying_permission(): void
    {
        $owner = $this->user();
        $business = $this->business($owner);

        $restricted = $this->user('Restricted');
        $role = Role::create([
            'business_id' => $business->id,
            'name' => 'Assistant Only',
            'slug' => 'assistant-only',
            'is_system' => false,
        ]);
        $permissionId = DB::table('permissions')->where('name', 'assistant.use')->value('id');
        $role->permissions()->sync([$permissionId]);

        $membership = BusinessMembership::create([
            'business_id' => $business->id,
            'user_id' => $restricted->id,
        ]);
        $membership->assignRole($role);

        $this->actIn($restricted, $business);

        $this->post(route('system.assistant.ask'), [
            'question' => 'What is our current stock position?',
        ])->assertRedirect();

        $answer = SmartAssistantMessage::query()
            ->where('user_id', $restricted->id)
            ->where('role', 'assistant')
            ->firstOrFail();

        $this->assertSame([], $answer->meta['facts']);
        $this->assertStringNotContainsString('stock quantity', strtolower($answer->content));
    }

    public function test_business_workspace_lists_only_user_memberships_and_switches_business(): void
    {
        $user = $this->user();
        $businessA = $this->business($user, 'Alpha Business');
        $businessB = $this->business($user, 'Beta Business');

        $stranger = $this->user('Stranger');
        $this->business($stranger, 'Hidden Business');

        $this->actIn($user, $businessA);

        $this->get(route('system.businesses.index'))
            ->assertOk()
            ->assertSee('Alpha Business')
            ->assertSee('Beta Business')
            ->assertDontSee('Hidden Business');

        $this->post(route('business.switch'), [
            'business_id' => $businessB->id,
        ])->assertRedirect(route('app.home'));

        $this->assertSame($businessB->id, session(config('business.context.session_key')));
    }

    public function test_assistant_history_is_scoped_by_business_and_user(): void
    {
        $user = $this->user();
        $businessA = $this->business($user, 'Alpha');
        $businessB = $this->business($user, 'Beta');

        $this->actIn($user, $businessA);
        $this->post(route('system.assistant.ask'), ['question' => 'Give me a business overview'])->assertRedirect();
        $this->assertSame(2, SmartAssistantMessage::query()->count());

        $this->actIn($user, $businessB);
        $this->assertSame(0, SmartAssistantMessage::query()->count());

        $this->post(route('system.assistant.ask'), ['question' => 'Give me a business overview'])->assertRedirect();
        $this->assertSame(2, SmartAssistantMessage::query()->count());

        $this->post(route('system.assistant.clear'))->assertRedirect();
        $this->assertSame(0, SmartAssistantMessage::query()->count());

        $this->actIn($user, $businessA);
        $this->assertSame(2, SmartAssistantMessage::query()->count());
    }
}
