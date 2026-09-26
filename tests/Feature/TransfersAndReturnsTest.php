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

class TransfersAndReturnsTest extends TestCase
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

    private function user(): User
    {
        return User::create([
            'name' => 'Inventory Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function business(User $user): Business
    {
        $business = Business::create(['name' => 'Inventory Business']);
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

    private function product(string $name = 'Stock Item', string $sku = 'STK-001', string $price = '100.0000'): Product
    {
        return Product::create([
            'type' => 'product',
            'name' => $name,
            'sku' => $sku,
            'sale_price' => $price,
        ]);
    }

    public function test_transfer_and_return_schema_and_numbering_are_available(): void
    {
        foreach ([
            'warehouse_transfers',
            'warehouse_transfer_items',
            'inventory_returns',
            'inventory_return_items',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertSame('TRF', config('numbering.prefixes.warehouse_transfer'));
        $this->assertSame('RET', config('numbering.prefixes.inventory_return'));
    }

    public function test_transfer_and_return_pages_render_with_inventory_actions(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        Warehouse::create(['code' => 'MAIN', 'name' => 'Main Warehouse', 'is_active' => true]);
        $this->product();

        $this->get('/inventory')
            ->assertOk()
            ->assertSee(__('operations.transfers.title'))
            ->assertSee(__('operations.returns.title'));

        $this->get('/inventory/transfers')
            ->assertOk()
            ->assertSee(__('operations.transfers.new'));

        $this->get('/inventory/returns')
            ->assertOk()
            ->assertSee(__('operations.returns.sales_return'))
            ->assertSee(__('operations.returns.purchase_return'));
    }

    public function test_warehouse_transfer_moves_stock_only_after_dispatch_and_receipt(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $source = Warehouse::create(['code' => 'A', 'name' => 'Warehouse A', 'is_active' => true]);
        $destination = Warehouse::create(['code' => 'B', 'name' => 'Warehouse B', 'is_active' => true]);
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
            'note' => 'Move stock',
        ])->assertRedirect();

        $transfer = WarehouseTransfer::with('items')->firstOrFail();
        $this->assertSame('TRF-000001', $transfer->number);
        $this->assertSame('draft', $transfer->status);
        $this->assertSame(10.0, (float) StockMovement::where('warehouse_id', $source->id)->sum('quantity'));
        $this->assertSame(0.0, (float) StockMovement::where('warehouse_id', $destination->id)->sum('quantity'));

        $this->post('/inventory/transfers/'.$transfer->id.'/dispatch')->assertRedirect();

        $transfer->refresh();
        $this->assertSame('in_transit', $transfer->status);
        $this->assertSame(6.0, (float) StockMovement::where('warehouse_id', $source->id)->sum('quantity'));
        $this->assertSame(0.0, (float) StockMovement::where('warehouse_id', $destination->id)->sum('quantity'));
        $this->assertSame('25.0000', $transfer->items()->firstOrFail()->unit_cost);

        $this->post('/inventory/transfers/'.$transfer->id.'/dispatch')
            ->assertSessionHasErrors('transfer');

        $this->post('/inventory/transfers/'.$transfer->id.'/receive')->assertRedirect();

        $transfer->refresh();
        $this->assertSame('received', $transfer->status);
        $this->assertSame(6.0, (float) StockMovement::where('warehouse_id', $source->id)->sum('quantity'));
        $this->assertSame(4.0, (float) StockMovement::where('warehouse_id', $destination->id)->sum('quantity'));

        $this->post('/inventory/transfers/'.$transfer->id.'/receive')
            ->assertSessionHasErrors('transfer');

        $this->assertSame(1, StockMovement::where('type', 'transfer_out')->count());
        $this->assertSame(1, StockMovement::where('type', 'transfer_in')->count());
    }

    public function test_transfer_dispatch_refuses_insufficient_stock_atomically(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $source = Warehouse::create(['code' => 'LOW', 'name' => 'Low Stock', 'is_active' => true]);
        $destination = Warehouse::create(['code' => 'DEST', 'name' => 'Destination', 'is_active' => true]);
        $product = $this->product('Scarce Stock', 'SCARCE-001');

        StockMovement::create([
            'warehouse_id' => $source->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '2.0000',
            'unit_cost' => '5.0000',
            'occurred_at' => now(),
        ]);

        $this->post('/inventory/transfers', [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'product_id' => $product->id,
            'quantity' => '3.0000',
        ])->assertRedirect();

        $transfer = WarehouseTransfer::firstOrFail();

        $this->post('/inventory/transfers/'.$transfer->id.'/dispatch')
            ->assertSessionHasErrors('transfer');

        $transfer->refresh();
        $this->assertSame('draft', $transfer->status);
        $this->assertSame(0, StockMovement::where('type', 'transfer_out')->count());
        $this->assertSame(2.0, (float) StockMovement::where('warehouse_id', $source->id)->sum('quantity'));
    }

    public function test_purchase_return_reduces_stock_and_prevents_over_return(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main Warehouse', 'is_active' => true]);
        $product = $this->product();
        $supplier = Supplier::create(['name' => 'Supplier', 'is_active' => true]);

        $this->post('/purchasing/orders', [
            'supplier_id' => $supplier->id,
            'number' => 'PO-RET-1',
            'order_date' => '2026-09-26',
            'product_id' => $product->id,
            'quantity' => '10.0000',
            'unit_cost' => '20.0000',
        ])->assertRedirect();

        $order = PurchaseOrder::with('items')->firstOrFail();

        $this->post('/purchasing/orders/'.$order->id.'/receive', [
            'warehouse_id' => $warehouse->id,
        ])->assertRedirect();

        $item = $order->items->firstOrFail();

        $this->post('/inventory/returns/purchases', [
            'purchase_order_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '3.0000',
            'reason' => 'Damaged cartons',
        ])->assertRedirect();

        $return = InventoryReturn::with('items')->firstOrFail();
        $this->assertSame('RET-000001', $return->number);
        $this->assertSame('purchase', $return->type);
        $this->assertSame('60.0000', $return->total);
        $this->assertSame(7.0, (float) StockMovement::where('product_id', $product->id)->sum('quantity'));

        $this->post('/inventory/returns/purchases', [
            'purchase_order_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '8.0000',
            'reason' => 'Trying to return too many',
        ])->assertSessionHasErrors('return');

        $this->assertSame(1, InventoryReturn::where('type', 'purchase')->count());
        $this->assertSame(7.0, (float) StockMovement::where('product_id', $product->id)->sum('quantity'));
    }

    public function test_partial_pos_sales_return_restores_stock_posts_reversal_and_reconciles_cash_shift(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $warehouse = Warehouse::create(['code' => 'POS-WH', 'name' => 'POS Warehouse', 'is_active' => true]);
        $register = PosRegister::create([
            'warehouse_id' => $warehouse->id,
            'code' => 'REG-1',
            'name' => 'Register 1',
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

        $this->post('/pos/registers/'.$register->id.'/open-shift', ['opening_cash' => '100.0000'])
            ->assertRedirect();

        $shift = PosShift::firstOrFail();

        $this->post('/pos/shifts/'.$shift->id.'/checkout', [
            'items' => json_encode([['product_id' => $product->id, 'quantity' => 2]]),
            'payment_method' => 'cash',
            'amount_tendered' => '200.0000',
        ])->assertRedirect();

        $sale = PosSale::with('items')->firstOrFail();
        $saleItem = $sale->items->firstOrFail();

        $this->assertSame(8.0, (float) StockMovement::where('product_id', $product->id)->sum('quantity'));

        $this->post('/inventory/returns/sales', [
            'pos_sale_item_id' => $saleItem->id,
            'quantity' => '1.0000',
            'reason' => 'Customer returned one item',
        ])->assertRedirect();

        $return = InventoryReturn::where('type', 'sales')->firstOrFail();
        $this->assertSame('100.0000', $return->total);
        $this->assertSame(9.0, (float) StockMovement::where('product_id', $product->id)->sum('quantity'));

        $entry = JournalEntry::with('lines')
            ->where('source_type', InventoryReturn::class)
            ->where('source_id', $return->id)
            ->firstOrFail();

        $this->assertEquals(
            $entry->lines->sum(fn ($line) => (float) $line->debit),
            $entry->lines->sum(fn ($line) => (float) $line->credit),
        );

        $this->post('/inventory/returns/sales', [
            'pos_sale_item_id' => $saleItem->id,
            'quantity' => '2.0000',
            'reason' => 'Over return',
        ])->assertSessionHasErrors('return');

        $this->post('/pos/sales/'.$sale->id.'/void', [
            'reason' => 'Should not double reverse',
        ])->assertSessionHasErrors('sale');

        $this->post('/pos/shifts/'.$shift->id.'/close', [
            'closing_cash' => '200.0000',
        ])->assertRedirect();

        $shift->refresh();
        $this->assertSame('200.0000', $shift->expected_cash);
        $this->assertSame('0.0000', $shift->cash_variance);
    }

    public function test_cross_business_transfer_references_are_rejected(): void
    {
        $owner = $this->user();
        $business = $this->business($owner);
        $this->actIn($owner, $business);
        $source = Warehouse::create(['code' => 'LOCAL', 'name' => 'Local', 'is_active' => true]);

        $other = $this->user();
        $otherBusiness = $this->business($other);
        $this->actIn($other, $otherBusiness);
        $foreignWarehouse = Warehouse::create(['code' => 'FOREIGN', 'name' => 'Foreign', 'is_active' => true]);
        $foreignProduct = $this->product('Foreign Product', 'FOREIGN-1');

        $this->actIn($owner, $business);

        $this->post('/inventory/transfers', [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $foreignWarehouse->id,
            'product_id' => $foreignProduct->id,
            'quantity' => '1.0000',
        ])->assertSessionHasErrors(['destination_warehouse_id', 'product_id']);

        $this->assertDatabaseCount('warehouse_transfers', 0);
    }
}
