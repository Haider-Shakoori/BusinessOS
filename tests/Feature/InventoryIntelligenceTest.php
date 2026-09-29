<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\BusinessNotification;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryIntelligenceService;
use App\Services\InventoryLowStockAlertService;
use App\Services\InventoryReorderService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryIntelligenceTest extends TestCase
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

    private function owner(string $businessName = 'Inventory Intelligence Business'): array
    {
        $user = User::create([
            'name' => 'Inventory Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create(['name' => $businessName]);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (['dashboard', 'settings', 'products', 'inventory', 'notifications'] as $module) {
            BusinessModule::updateOrCreate(
                ['business_id' => $business->id, 'module_key' => $module],
                ['enabled' => true],
            );
        }

        $this->actIn($user, $business);

        return [$user, $business];
    }

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();
    }

    private function product(string $name = 'Intelligence Product', string $sku = 'INT-1'): Product
    {
        return Product::create([
            'type' => 'product',
            'name' => $name,
            'sku' => $sku,
            'sale_price' => '100.0000',
        ]);
    }

    public function test_inventory_intelligence_workspace_is_available_from_inventory(): void
    {
        $this->owner();

        $this->get(route('inventory.intelligence.index'))
            ->assertOk()
            ->assertSee(__('operations.inventory_intelligence.title'));

        $this->get(route('inventory.index'))
            ->assertOk()
            ->assertSee(__('operations.inventory_intelligence.title'));
    }

    public function test_intelligence_calculates_valuation_slow_stock_and_movement_totals(): void
    {
        $this->owner();
        $warehouse = Warehouse::create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
        $product = $this->product();

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '10.0000',
            'unit_cost' => '10.0000',
            'occurred_at' => now()->subDays(90),
        ]);

        app(InventoryReorderService::class)->saveRule(
            $warehouse,
            $product,
            null,
            '15.0000',
            '20.0000',
        );

        $data = app(InventoryIntelligenceService::class)->dashboard(
            $warehouse->id,
            $product->id,
            60,
            now()->subDays(120)->toDateString(),
            now()->toDateString(),
        );

        $this->assertSame('10.0000', $data['summary']['stock_quantity']);
        $this->assertSame('100.0000', $data['summary']['stock_value']);
        $this->assertSame(1, $data['summary']['slow_moving']);
        $this->assertSame(1, $data['summary']['low_stock']);
        $this->assertSame(0, $data['summary']['out_of_stock']);
        $this->assertSame('10.0000', $data['summary']['inbound_quantity']);
        $this->assertSame('0.0000', $data['summary']['outbound_quantity']);
        $this->assertSame('10.0000', $data['summary']['net_movement']);

        $row = $data['valuation_rows']->first();
        $this->assertTrue($row['is_slow']);
        $this->assertSame('10.0000', $row['average_unit_cost']);
        $this->assertSame('100.0000', $row['stock_value']);
        $this->assertGreaterThanOrEqual(90, $row['inactive_days']);
    }

    public function test_low_stock_alert_is_deduplicated_and_resolves_when_stock_recovers(): void
    {
        [$user, $business] = $this->owner();
        $warehouse = Warehouse::create([
            'code' => 'LOW',
            'name' => 'Low Stock Warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
        $product = $this->product('Low Product', 'LOW-1');

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '2.0000',
            'unit_cost' => '5.0000',
            'occurred_at' => now(),
        ]);

        app(InventoryReorderService::class)->saveRule(
            $warehouse,
            $product,
            null,
            '5.0000',
            '10.0000',
        );

        $alerts = app(InventoryLowStockAlertService::class);
        $first = $alerts->scanBusiness($business->id);
        $second = $alerts->scanBusiness($business->id);

        $this->assertSame(1, $first['notifications']);
        $this->assertSame(0, $second['notifications']);
        $this->assertSame(1, $second['attention_items']);

        $notification = BusinessNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'inventory_low_stock')
            ->firstOrFail();

        $this->assertNull($notification->read_at);
        $this->assertSame(1, BusinessNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'inventory_low_stock')
            ->count());

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => '10.0000',
            'unit_cost' => '5.0000',
            'occurred_at' => now(),
        ]);

        $resolved = $alerts->scanBusiness($business->id);

        $this->assertSame(0, $resolved['attention_items']);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_manual_refresh_and_scheduled_command_use_same_alert_scanner(): void
    {
        [$user, $business] = $this->owner();
        $warehouse = Warehouse::create([
            'code' => 'CMD',
            'name' => 'Command Warehouse',
            'is_active' => true,
            'is_default' => true,
        ]);
        $product = $this->product('Command Product', 'CMD-1');

        app(InventoryReorderService::class)->saveRule(
            $warehouse,
            $product,
            null,
            '2.0000',
            '8.0000',
        );

        $this->post(route('inventory.intelligence.alerts.refresh'))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, BusinessNotification::query()
            ->where('user_id', $user->id)
            ->where('type', 'inventory_low_stock')
            ->whereNull('read_at')
            ->count());

        BusinessNotification::query()
            ->where('type', 'inventory_low_stock')
            ->update(['read_at' => now()]);

        auth()->logout();
        $this->flushSession();
        $this->app->forgetScopedInstances();

        $this->artisan('inventory:reorder-alerts')->assertExitCode(0);

        $this->assertSame(1, BusinessNotification::withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->where('type', 'inventory_low_stock')
            ->whereNull('read_at')
            ->count());
    }

    public function test_intelligence_workspace_is_tenant_isolated(): void
    {
        [$ownerA, $businessA] = $this->owner('Business A');
        $warehouseA = Warehouse::create(['code' => 'A', 'name' => 'Warehouse A', 'is_active' => true]);
        $productA = $this->product('Product A', 'A-1');
        StockMovement::create([
            'warehouse_id' => $warehouseA->id,
            'product_id' => $productA->id,
            'type' => 'opening',
            'quantity' => '5.0000',
            'unit_cost' => '10.0000',
            'occurred_at' => now(),
        ]);

        [$ownerB, $businessB] = $this->owner('Business B');
        $warehouseB = Warehouse::create(['code' => 'B', 'name' => 'Warehouse B', 'is_active' => true]);
        $productB = $this->product('Product B', 'B-1');
        StockMovement::create([
            'warehouse_id' => $warehouseB->id,
            'product_id' => $productB->id,
            'type' => 'opening',
            'quantity' => '7.0000',
            'unit_cost' => '20.0000',
            'occurred_at' => now(),
        ]);

        $this->actIn($ownerA, $businessA);

        $this->get(route('inventory.intelligence.index'))
            ->assertOk()
            ->assertSee('Product A')
            ->assertDontSee('Product B');
    }
}
