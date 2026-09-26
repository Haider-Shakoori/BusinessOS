<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\InventoryReturn;
use App\Models\JournalEntry;
use App\Models\PosRegister;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryTransfersReturnsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    private function owner(): array
    {
        $user = User::create([
            'name' => 'Operations Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create(['name' => 'Operations Business']);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (['dashboard', 'settings', 'products', 'inventory', 'purchasing', 'accounting', 'pos'] as $module) {
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

    private function product(string $name, string $sku): Product
    {
        return Product::create([
            'type' => 'product',
            'name' => $name,
            'sku' => $sku,
            'sale_price' => '10.0000',
        ]);
    }

    public function test_transfer_dispatches_and_receives_stock_between_warehouses(): void
    {
        $this->owner();

        $source = Warehouse::create(['code' => 'SRC', 'name' => 'Source', 'is_active' => true]);
        $destination = Warehouse::create(['code' => 'DST', 'name' => 'Destination', 'is_active' => true]);
        $product = $this->product('Transfer Product', 'TRF-PROD');

        StockMovement::create([
            'warehouse_id' => $source->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => 10,
            'unit_cost' => 5,
            'occurred_at' => now(),
        ]);

        $this->post('/inventory/transfers', [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'product_id' => $product->id,
            'quantity' => 4,
            'note' => 'Move stock',
        ])->assertRedirect();

        $transfer = WarehouseTransfer::with('items')->firstOrFail();
        $this->assertSame('draft', $transfer->status);
        $this->assertStringStartsWith('TRF-', $transfer->number);

        $this->post('/inventory/transfers/'.$transfer->id.'/dispatch')->assertRedirect();

        $transfer->refresh();
        $this->assertSame('in_transit', $transfer->status);
        $this->assertSame(
            6.0,
            (float) StockMovement::where('warehouse_id', $source->id)->where('product_id', $product->id)->sum('quantity'),
        );
        $this->assertSame(
            0.0,
            (float) StockMovement::where('warehouse_id', $destination->id)->where('product_id', $product->id)->sum('quantity'),
        );

        $this->post('/inventory/transfers/'.$transfer->id.'/receive')->assertRedirect();

        $transfer->refresh();
        $this->assertSame('received', $transfer->status);
        $this->assertSame(
            4.0,
            (float) StockMovement::where('warehouse_id', $destination->id)->where('product_id', $product->id)->sum('quantity'),
        );
        $this->assertSame(1, StockMovement::where('type', 'transfer_out')->count());
        $this->assertSame(1, StockMovement::where('type', 'transfer_in')->count());

        $this->post('/inventory/transfers/'.$transfer->id.'/receive')
            ->assertRedirect()
            ->assertSessionHasErrors('transfer');

        $this->assertSame(1, StockMovement::where('type', 'transfer_in')->count());
    }

    public function test_transfer_dispatch_refuses_insufficient_stock_atomically(): void
    {
        $this->owner();

        $source = Warehouse::create(['code' => 'A', 'name' => 'A', 'is_active' => true]);
        $destination = Warehouse::create(['code' => 'B', 'name' => 'B', 'is_active' => true]);
        $product = $this->product('Scarce Product', 'SCARCE');

        StockMovement::create([
            'warehouse_id' => $source->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => 2,
            'occurred_at' => now(),
        ]);

        $this->post('/inventory/transfers', [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ])->assertRedirect();

        $transfer = WarehouseTransfer::firstOrFail();

        $this->post('/inventory/transfers/'.$transfer->id.'/dispatch')
            ->assertRedirect()
            ->assertSessionHasErrors('transfer');

        $transfer->refresh();
        $this->assertSame('draft', $transfer->status);
        $this->assertSame(0, StockMovement::where('type', 'transfer_out')->count());
    }

    public function test_partial_pos_sales_return_restores_stock_and_posts_balanced_reversal(): void
    {
        [$user] = $this->owner();

        $warehouse = Warehouse::create(['code' => 'POS', 'name' => 'POS Warehouse', 'is_active' => true]);
        $product = $this->product('POS Product', 'POS-PROD');

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => 10,
            'unit_cost' => 4,
            'occurred_at' => now(),
        ]);

        $register = PosRegister::create([
            'warehouse_id' => $warehouse->id,
            'code' => 'R1',
            'name' => 'Register 1',
            'is_active' => true,
        ]);

        $shift = PosShift::create([
            'pos_register_id' => $register->id,
            'user_id' => $user->id,
            'status' => 'open',
            'opened_at' => now(),
            'opening_cash' => 0,
        ]);

        $sale = PosSale::create([
            'pos_register_id' => $register->id,
            'pos_shift_id' => $shift->id,
            'cashier_id' => $user->id,
            'sale_number' => 'POS-TEST-1',
            'status' => 'completed',
            'payment_method' => 'cash',
            'subtotal' => 20,
            'discount_amount' => 2,
            'tax_amount' => 1,
            'total' => 19,
            'amount_tendered' => 20,
            'change_due' => 1,
            'currency_code' => 'AFN',
            'completed_at' => now(),
        ]);

        $item = $sale->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'quantity' => 2,
            'unit_price' => 10,
            'unit_cost' => 4,
            'cost_total' => 8,
            'tax_rate' => 5,
            'tax_amount' => 1,
            'line_total' => 21,
        ]);

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'sale',
            'quantity' => -2,
            'unit_cost' => 4,
            'reference_type' => PosSale::class,
            'reference_id' => $sale->id,
            'occurred_at' => now(),
        ]);

        $this->post('/inventory/returns/sales', [
            'pos_sale_item_id' => $item->id,
            'quantity' => 1,
            'reason' => 'Customer returned one item',
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_returns', [
            'type' => 'sales',
            'source_id' => $sale->id,
            'status' => 'completed',
            'total' => 9.5,
        ]);

        $this->assertSame(
            9.0,
            (float) StockMovement::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->sum('quantity'),
        );

        $journal = JournalEntry::query()->where('source_type', InventoryReturn::class)->with('lines')->firstOrFail();
        $this->assertEquals(
            $journal->lines->sum(fn ($line) => (float) $line->debit),
            $journal->lines->sum(fn ($line) => (float) $line->credit),
        );

        $this->post('/inventory/returns/sales', [
            'pos_sale_item_id' => $item->id,
            'quantity' => 2,
            'reason' => 'Attempt to over-return',
        ])->assertSessionHasErrors('return');
    }

    public function test_purchase_return_deducts_stock_and_cannot_exceed_original_quantity(): void
    {
        $this->owner();

        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $product = $this->product('Purchased Product', 'PUR-PROD');
        $supplier = Supplier::create(['name' => 'Supplier', 'is_active' => true]);

        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'number' => 'PO-RETURN-1',
            'status' => 'received',
            'order_date' => now()->toDateString(),
            'subtotal' => 15,
            'total' => 15,
        ]);

        $item = $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 5,
            'unit_cost' => 3,
            'line_total' => 15,
        ]);

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 5,
            'unit_cost' => 3,
            'reference_type' => PurchaseOrder::class,
            'reference_id' => $order->id,
            'occurred_at' => now(),
        ]);

        $this->post('/inventory/returns/purchases', [
            'purchase_order_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'reason' => 'Damaged shipment',
        ])->assertRedirect();

        $this->assertDatabaseHas('inventory_returns', [
            'type' => 'purchase',
            'source_id' => $order->id,
            'total' => 6,
        ]);
        $this->assertSame(
            3.0,
            (float) StockMovement::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->sum('quantity'),
        );

        $this->post('/inventory/returns/purchases', [
            'purchase_order_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 4,
            'reason' => 'Too many',
        ])->assertSessionHasErrors('return');

        $this->assertSame(1, StockMovement::where('type', 'purchase_return')->count());
    }
}
