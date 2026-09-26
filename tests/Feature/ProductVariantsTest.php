<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\InventoryReturn;
use App\Models\PosRegister;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductVariantsTest extends TestCase
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
            'name' => 'Variant Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create(['name' => 'Variant Business']);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (['dashboard', 'settings', 'products', 'inventory', 'purchasing', 'accounting', 'pos'] as $module) {
            BusinessModule::updateOrCreate(
                ['business_id' => $business->id, 'module_key' => $module],
                ['enabled' => true],
            );
        }

        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();

        return [$user, $business];
    }

    private function product(string $name = 'Variant Product', string $sku = 'VP-001', string $price = '100.0000'): Product
    {
        return Product::create([
            'type' => 'product',
            'name' => $name,
            'sku' => $sku,
            'sale_price' => $price,
        ]);
    }

    private function variant(Product $product, string $name, string $sku, ?string $price = null): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $product->id,
            'name' => $name,
            'sku' => $sku,
            'sale_price' => $price,
            'is_active' => true,
        ]);
    }

    public function test_variant_schema_and_management_are_available(): void
    {
        $this->owner();
        $product = $this->product();

        $this->assertTrue(Schema::hasTable('product_variants'));
        foreach (['stock_movements', 'purchase_order_items', 'pos_sale_items', 'warehouse_transfer_items', 'inventory_return_items'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'product_variant_id'));
        }

        $this->post('/products/'.$product->id.'/variants', [
            'name' => 'Large / Red',
            'sku' => 'VP-L-RED',
            'sale_price' => '125.0000',
        ])->assertRedirect();

        $variant = ProductVariant::firstOrFail();
        $this->assertSame('Large / Red', $variant->name);
        $this->assertSame('125.0000', $variant->sale_price);

        $this->get('/products/'.$product->id.'/variants')
            ->assertOk()
            ->assertSee('Large / Red')
            ->assertSee('VP-L-RED');
    }

    public function test_inventory_keeps_variant_stock_separate_and_requires_variant_when_active_variants_exist(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $product = $this->product();
        $large = $this->variant($product, 'Large', 'VP-L', '120.0000');
        $small = $this->variant($product, 'Small', 'VP-S', '90.0000');

        $this->post('/inventory/movements', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '10',
        ])->assertSessionHasErrors('product_variant_id');

        foreach ([[$large, '10.0000'], [$small, '3.0000']] as [$variant, $quantity]) {
            $this->post('/inventory/movements', [
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'type' => 'opening',
                'quantity' => $quantity,
                'unit_cost' => '50.0000',
            ])->assertRedirect();
        }

        $this->assertSame(10.0, (float) StockMovement::where('product_variant_id', $large->id)->sum('quantity'));
        $this->assertSame(3.0, (float) StockMovement::where('product_variant_id', $small->id)->sum('quantity'));
        $this->assertSame(0, StockMovement::whereNull('product_variant_id')->count());

        $this->get('/inventory')
            ->assertOk()
            ->assertSee('Large')
            ->assertSee('Small');
    }

    public function test_wrong_variant_cannot_be_attached_to_another_product(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $first = $this->product('First Product', 'FIRST');
        $second = $this->product('Second Product', 'SECOND');
        $foreignToFirst = $this->variant($second, 'Second Variant', 'SECOND-V');

        $this->post('/inventory/movements', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $first->id,
            'product_variant_id' => $foreignToFirst->id,
            'type' => 'opening',
            'quantity' => '1',
        ])->assertSessionHasErrors('product_variant_id');

        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_purchase_receipt_increases_only_selected_variant_stock(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'PUR', 'name' => 'Purchasing', 'is_active' => true]);
        $product = $this->product();
        $large = $this->variant($product, 'Large', 'PUR-L');
        $small = $this->variant($product, 'Small', 'PUR-S');
        $supplier = Supplier::create(['name' => 'Supplier', 'is_active' => true]);

        $this->post('/purchasing/orders', [
            'supplier_id' => $supplier->id,
            'number' => 'PO-VAR-1',
            'order_date' => '2026-09-26',
            'product_id' => $product->id,
            'product_variant_id' => $large->id,
            'quantity' => '8.0000',
            'unit_cost' => '40.0000',
        ])->assertRedirect();

        $order = PurchaseOrder::with('items')->firstOrFail();
        $this->assertSame($large->id, $order->items->first()->product_variant_id);

        $this->post('/purchasing/orders/'.$order->id.'/receive', [
            'warehouse_id' => $warehouse->id,
        ])->assertRedirect();

        $this->assertSame(8.0, (float) StockMovement::where('product_variant_id', $large->id)->sum('quantity'));
        $this->assertSame(0.0, (float) StockMovement::where('product_variant_id', $small->id)->sum('quantity'));
    }

    public function test_pos_variant_uses_its_own_price_stock_and_sales_return_restores_same_variant(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'POS', 'name' => 'POS', 'is_active' => true]);
        $register = PosRegister::create([
            'warehouse_id' => $warehouse->id,
            'code' => 'REG-V',
            'name' => 'Variant Register',
            'is_active' => true,
        ]);
        $product = $this->product(price: '100.0000');
        $large = $this->variant($product, 'Large', 'POS-L', '150.0000');
        $small = $this->variant($product, 'Small', 'POS-S', '80.0000');

        foreach ([[$large, 5], [$small, 2]] as [$variant, $quantity]) {
            StockMovement::create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'type' => 'opening',
                'quantity' => $quantity,
                'unit_cost' => '60.0000',
                'occurred_at' => now(),
            ]);
        }

        $this->post('/pos/registers/'.$register->id.'/open-shift', ['opening_cash' => 0])->assertRedirect();
        $shift = PosShift::firstOrFail();

        $this->post('/pos/shifts/'.$shift->id.'/checkout', [
            'items' => json_encode([[
                'product_id' => $product->id,
                'product_variant_id' => $large->id,
                'quantity' => 2,
            ]]),
            'payment_method' => 'cash',
            'amount_tendered' => '300.0000',
        ])->assertRedirect();

        $sale = PosSale::with('items')->firstOrFail();
        $item = $sale->items->firstOrFail();

        $this->assertSame('300.0000', $sale->total);
        $this->assertSame($large->id, $item->product_variant_id);
        $this->assertSame('Variant Product — Large', $item->product_name);
        $this->assertSame('POS-L', $item->sku);
        $this->assertSame('150.0000', $item->unit_price);
        $this->assertSame(3.0, (float) StockMovement::where('product_variant_id', $large->id)->sum('quantity'));
        $this->assertSame(2.0, (float) StockMovement::where('product_variant_id', $small->id)->sum('quantity'));

        $this->post('/inventory/returns/sales', [
            'pos_sale_item_id' => $item->id,
            'quantity' => '1.0000',
            'reason' => 'Variant return',
        ])->assertRedirect();

        $return = InventoryReturn::with('items')->firstOrFail();
        $this->assertSame($large->id, $return->items->first()->product_variant_id);
        $this->assertSame(4.0, (float) StockMovement::where('product_variant_id', $large->id)->sum('quantity'));
        $this->assertSame(2.0, (float) StockMovement::where('product_variant_id', $small->id)->sum('quantity'));
    }
}
