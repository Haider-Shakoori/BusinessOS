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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransfersReturnsTest extends TestCase
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

    private function user(string $name = 'Operations Owner'): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function business(User $user, string $name = 'Operations Co'): Business
    {
        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (['dashboard', 'settings', 'products', 'inventory', 'purchasing', 'accounting', 'pos'] as $module) {
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

    private function product(string $name = 'Stock Product', string $sku = 'STK-001', string $price = '100.0000'): Product
    {
        return Product::create([
            'type' => 'product',
            'name' => $name,
            'sku' => $sku,
            'sale_price' => $price,
        ]);
    }

    public function test_transfer_return_schema_and_numbering_are_present(): void
    {
        foreach (['warehouse_transfers', 'warehouse_transfer_items', 'inventory_returns', 'inventory_return_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertSame('TRF', config('numbering.prefixes.warehouse_transfer'));
        $this->assertSame('RET', config('numbering.prefixes.inventory_return'));
    }

    public function test_warehouse_transfer_moves_stock_only_once_through_dispatch_and_receipt(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $source = Warehouse::create(['code' => 'SRC', 'name' => 'Source', 'is_active' => true]);
        $destination = Warehouse::create(['code' => 'DST', 'name' => 'Destination', 'is_active' => true]);
        $product = $this->product();

        StockMovement::create([
            'warehouse_id' => $source->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '10.0000',
            'unit_cost' => '25.0000',
            'occurred_at' => now(),
        ]);

        $this->post('/inventory/transfers', [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'product_id' => $product->id,
            'quantity' => '4.0000',
            'note' => 'Rebalance stock',
        ])->assertRedirect();

        $transfer = WarehouseTransfer::with('items')->firstOrFail();
        $this->assertSame('TRF-000001', $transfer->number);
        $this->assertSame('draft', $transfer->status);

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

        $this->post('/inventory/transfers/'.$transfer->id.'/receive')
            ->assertSessionHasErrors('transfer');

        $this->assertSame(
            1,
            StockMovement::where('reference_type', WarehouseTransfer::class)
                ->where('reference_id', $transfer->id)
                ->where('type', 'transfer_in')
                ->count(),
        );
    }

    public function test_partial_cash_sales_return_restores_stock_reverses_accounting_and_reduces_shift_cash(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $warehouse = Warehouse::create(['code' => 'POS-WH', 'name' => 'POS Warehouse', 'is_active' => true]);
        $register = PosRegister::create([
            'warehouse_id' => $warehouse->id,
            'code' => 'REG-01',
            'name' => 'Main Register',
            'is_active' => true,
        ]);
        $product = $this->product();

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '10.0000',
            'unit_cost' => '60.0000',
            'occurred_at' => now(),
        ]);

        $this->post('/pos/registers/'.$register->id.'/open-shift', ['opening_cash' => '50.0000'])->assertRedirect();
        $shift = PosShift::firstOrFail();

        $this->post('/pos/shifts/'.$shift->id.'/checkout', [
            'items' => json_encode([['product_id' => $product->id, 'quantity' => 2]]),
            'payment_method' => 'cash',
            'discount_amount' => 0,
            'amount_tendered' => '200.0000',
        ])->assertRedirect();

        $sale = PosSale::with('items')->firstOrFail();
        $item = $sale->items->firstOrFail();

        $this->post('/inventory/returns/sales', [
            'pos_sale_item_id' => $item->id,
            'quantity' => '1.0000',
            'reason' => 'Customer returned one unit',
        ])->assertRedirect();

        $return = InventoryReturn::with('items')->firstOrFail();
        $this->assertSame('RET-000001', $return->number);
        $this->assertSame('sales', $return->type);
        $this->assertSame('100.0000', $return->total);
        $this->assertSame(
            9.0,
            (float) StockMovement::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->sum('quantity'),
        );

        $journal = JournalEntry::with('lines')
            ->where('source_type', InventoryReturn::class)
            ->where('source_id', $return->id)
            ->firstOrFail();

        $this->assertEqualsWithDelta(
            (float) $journal->lines->sum('debit'),
            (float) $journal->lines->sum('credit'),
            0.0001,
        );

        $this->post('/pos/sales/'.$sale->id.'/void', [
            'reason' => 'Should not double reverse',
        ])->assertSessionHasErrors('sale');

        $this->post('/pos/shifts/'.$shift->id.'/close', [
            'closing_cash' => '150.0000',
        ])->assertRedirect();

        $shift->refresh();
        $this->assertSame('150.0000', $shift->expected_cash);
        $this->assertSame('0.0000', $shift->cash_variance);
    }

    public function test_purchase_return_deducts_stock_and_prevents_over_return(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main Warehouse', 'is_active' => true]);
        $product = $this->product('Purchased Product', 'PUR-001', '15.0000');
        $supplier = Supplier::create(['name' => 'Supplier', 'is_active' => true]);

        $this->post('/purchasing/orders', [
            'supplier_id' => $supplier->id,
            'number' => 'PO-RET-001',
            'order_date' => '2026-09-26',
            'product_id' => $product->id,
            'quantity' => '10.0000',
            'unit_cost' => '7.5000',
        ])->assertRedirect();

        $order = PurchaseOrder::with('items')->firstOrFail();

        $this->post('/purchasing/orders/'.$order->id.'/receive', [
            'warehouse_id' => $warehouse->id,
        ])->assertRedirect();

        $order->refresh();
        $item = $order->items()->firstOrFail();

        $this->post('/inventory/returns/purchases', [
            'purchase_order_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '3.0000',
            'reason' => 'Damaged on receipt',
        ])->assertRedirect();

        $return = InventoryReturn::firstOrFail();
        $this->assertSame('purchase', $return->type);
        $this->assertSame('22.5000', $return->total);
        $this->assertSame(
            7.0,
            (float) StockMovement::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->sum('quantity'),
        );

        $this->post('/inventory/returns/purchases', [
            'purchase_order_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '8.0000',
            'reason' => 'Attempt to exceed original',
        ])->assertSessionHasErrors('return');

        $this->assertDatabaseCount('inventory_returns', 1);
        $this->assertSame(
            7.0,
            (float) StockMovement::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->sum('quantity'),
        );
    }

    public function test_cross_business_transfer_route_is_not_visible(): void
    {
        $ownerA = $this->user('Owner A');
        $businessA = $this->business($ownerA, 'Business A');
        $this->actIn($ownerA, $businessA);

        $source = Warehouse::create(['code' => 'A1', 'name' => 'A Source', 'is_active' => true]);
        $destination = Warehouse::create(['code' => 'A2', 'name' => 'A Destination', 'is_active' => true]);
        $product = $this->product('A Product', 'A-001');

        $this->post('/inventory/transfers', [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'product_id' => $product->id,
            'quantity' => '1.0000',
        ])->assertRedirect();

        $transfer = WarehouseTransfer::firstOrFail();

        $ownerB = $this->user('Owner B');
        $businessB = $this->business($ownerB, 'Business B');
        $this->actIn($ownerB, $businessB);

        $this->post('/inventory/transfers/'.$transfer->id.'/dispatch')->assertNotFound();
    }
}
