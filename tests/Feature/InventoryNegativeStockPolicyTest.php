<?php

namespace Tests\Feature;

use App\Models\Bom;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\PosRegister;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BusinessSettings;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryNegativeStockPolicyTest extends TestCase
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

    private function owner(): array
    {
        $user = User::create([
            'name' => 'Inventory Policy Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create(['name' => 'Inventory Policy Business']);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach ([
            'dashboard',
            'settings',
            'products',
            'inventory',
            'pos',
            'purchasing',
            'accounting',
            'manufacturing',
        ] as $module) {
            BusinessModule::updateOrCreate(
                ['business_id' => $business->id, 'module_key' => $module],
                ['enabled' => true],
            );
        }

        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();

        return [$user, $business];
    }

    private function product(string $name, string $sku, string $salePrice = '100.0000'): Product
    {
        return Product::create([
            'type' => 'product',
            'name' => $name,
            'sku' => $sku,
            'sale_price' => $salePrice,
        ]);
    }

    private function enableNegativeStock(): void
    {
        app(BusinessSettings::class)->set('inventory.allow_negative_stock', true);
    }

    public function test_negative_stock_setting_defaults_off_and_can_be_saved(): void
    {
        $this->owner();

        $this->assertFalse((bool) app(BusinessSettings::class)->get('inventory.allow_negative_stock'));

        $this->get(route('settings.index'))
            ->assertOk()
            ->assertSee(__('settings.allow_negative_stock'));

        $this->patch(route('settings.update'), [
            'inventory.allow_negative_stock' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', [
            'group' => 'inventory',
            'key' => 'allow_negative_stock',
            'value' => '1',
            'type' => 'boolean',
        ]);
    }

    public function test_manual_issue_is_blocked_by_default_and_allowed_when_enabled(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'MAN', 'name' => 'Manual', 'is_active' => true]);
        $product = $this->product('Manual Product', 'MAN-1');

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '1.0000',
            'unit_cost' => '12.0000',
            'occurred_at' => now(),
        ]);

        $payload = [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'adjustment',
            'quantity' => '-2.0000',
        ];

        $this->post(route('inventory.movements.store'), $payload)
            ->assertSessionHasErrors('quantity');

        $this->enableNegativeStock();

        $this->post(route('inventory.movements.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $issue = StockMovement::query()->where('quantity', '<', 0)->latest('id')->firstOrFail();
        $this->assertSame('12.0000', $issue->unit_cost);
        $this->assertSame(-1.0, (float) StockMovement::query()->sum('quantity'));
    }

    public function test_pos_oversell_uses_latest_known_cost_when_enabled(): void
    {
        [$user] = $this->owner();
        $warehouse = Warehouse::create(['code' => 'POS', 'name' => 'POS', 'is_active' => true]);
        $register = PosRegister::create([
            'warehouse_id' => $warehouse->id,
            'code' => 'R-1',
            'name' => 'Register',
            'is_active' => true,
        ]);
        $product = $this->product('POS Product', 'POS-1');

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '1.0000',
            'unit_cost' => '25.0000',
            'occurred_at' => now()->subMinute(),
        ]);
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'adjustment',
            'quantity' => '-1.0000',
            'unit_cost' => '25.0000',
            'occurred_at' => now(),
        ]);

        $this->post(route('pos.shifts.open', $register), ['opening_cash' => 0])->assertRedirect();
        $shift = PosShift::query()->where('user_id', $user->id)->firstOrFail();

        $payload = [
            'items' => json_encode([['product_id' => $product->id, 'quantity' => 1]]),
            'payment_method' => 'cash',
            'amount_tendered' => '100.0000',
        ];

        $this->post(route('pos.checkout', $shift), $payload)
            ->assertSessionHasErrors('cart');

        $this->enableNegativeStock();

        $this->post(route('pos.checkout', $shift), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $sale = PosSale::with('items')->firstOrFail();
        $this->assertSame('25.0000', $sale->items->first()->unit_cost);
        $this->assertSame(-1.0, (float) StockMovement::query()->where('product_id', $product->id)->sum('quantity'));
    }

    public function test_transfer_can_dispatch_below_zero_only_when_enabled(): void
    {
        $this->owner();
        $source = Warehouse::create(['code' => 'SRC', 'name' => 'Source', 'is_active' => true]);
        $destination = Warehouse::create(['code' => 'DST', 'name' => 'Destination', 'is_active' => true]);
        $product = $this->product('Transfer Product', 'TR-1');

        StockMovement::create([
            'warehouse_id' => $source->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '1.0000',
            'unit_cost' => '9.0000',
            'occurred_at' => now()->subMinute(),
        ]);
        StockMovement::create([
            'warehouse_id' => $source->id,
            'product_id' => $product->id,
            'type' => 'adjustment',
            'quantity' => '-1.0000',
            'unit_cost' => '9.0000',
            'occurred_at' => now(),
        ]);

        $this->post(route('inventory.transfers.store'), [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'product_id' => $product->id,
            'quantity' => '2.0000',
        ])->assertRedirect();

        $transfer = \App\Models\WarehouseTransfer::with('items')->firstOrFail();

        $this->post(route('inventory.transfers.dispatch', $transfer))
            ->assertSessionHasErrors('transfer');

        $this->enableNegativeStock();

        $this->post(route('inventory.transfers.dispatch', $transfer))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $transfer->refresh()->load('items');
        $this->assertSame('9.0000', $transfer->items->first()->unit_cost);
        $this->assertSame(
            -2.0,
            (float) StockMovement::query()
                ->where('warehouse_id', $source->id)
                ->where('product_id', $product->id)
                ->sum('quantity'),
        );
    }

    public function test_purchase_return_can_take_stock_below_zero_only_when_enabled(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'RET', 'name' => 'Return', 'is_active' => true]);
        $product = $this->product('Return Product', 'RET-1');
        $supplier = Supplier::create(['name' => 'Supplier', 'is_active' => true]);

        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'number' => 'PO-NEG-1',
            'status' => 'received',
            'order_date' => now()->toDateString(),
            'subtotal' => '10.0000',
            'total' => '10.0000',
        ]);
        $item = $order->items()->create([
            'product_id' => $product->id,
            'quantity' => '2.0000',
            'unit_cost' => '5.0000',
            'line_total' => '10.0000',
        ]);

        $payload = [
            'purchase_order_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '1.0000',
            'reason' => 'Return against zero on-hand stock',
        ];

        $this->post(route('inventory.returns.purchases.store'), $payload)
            ->assertSessionHasErrors('return');

        $this->enableNegativeStock();

        $this->post(route('inventory.returns.purchases.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            -1.0,
            (float) StockMovement::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->sum('quantity'),
        );
    }

    public function test_manufacturing_consumption_is_strict_by_default_and_can_be_opted_out(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'MFG', 'name' => 'Manufacturing', 'is_active' => true]);
        $raw = $this->product('Raw Material', 'RAW-NEG');
        $finished = $this->product('Finished Product', 'FG-NEG');

        $bom = Bom::create([
            'product_id' => $finished->id,
            'code' => 'BOM-NEG',
            'version' => '1',
            'is_active' => true,
        ]);
        $bom->items()->create([
            'material_product_id' => $raw->id,
            'quantity' => '2.0000',
            'wastage_percent' => '0.0000',
        ]);

        $order = ProductionOrder::create([
            'bom_id' => $bom->id,
            'product_id' => $finished->id,
            'number' => 'MO-NEG',
            'status' => 'planned',
            'planned_quantity' => '1.0000',
        ]);

        $payload = [
            'warehouse_id' => $warehouse->id,
            'actual_quantity' => '1.0000',
        ];

        $this->post('/manufacturing/orders/'.$order->id.'/complete', $payload)
            ->assertSessionHasErrors('stock');

        $this->assertSame('planned', $order->fresh()->status);
        $this->assertSame(0, StockMovement::query()->count());

        $this->enableNegativeStock();

        $this->post('/manufacturing/orders/'.$order->id.'/complete', $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(
            -2.0,
            (float) StockMovement::query()
                ->where('product_id', $raw->id)
                ->where('type', 'production_out')
                ->sum('quantity'),
        );
        $this->assertSame(
            1.0,
            (float) StockMovement::query()
                ->where('product_id', $finished->id)
                ->where('type', 'production_in')
                ->sum('quantity'),
        );
    }
}
