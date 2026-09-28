<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\InventoryReorderRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Supplier;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryReorderService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryReorderPlanningTest extends TestCase
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

    private function owner(string $businessName = 'Reorder Business'): array
    {
        $user = User::create([
            'name' => 'Reorder Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create(['name' => $businessName]);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (['dashboard', 'settings', 'products', 'inventory', 'purchasing'] as $module) {
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

    private function product(string $name = 'Planning Product', string $sku = 'PLAN-1'): Product
    {
        return Product::create([
            'type' => 'product',
            'name' => $name,
            'sku' => $sku,
            'sale_price' => '100.0000',
        ]);
    }

    public function test_reorder_schema_and_workspace_are_available(): void
    {
        $this->assertTrue(Schema::hasTable('inventory_reorder_rules'));
        $this->assertTrue(Schema::hasColumn('purchase_requisitions', 'warehouse_id'));
        $this->assertTrue(Schema::hasColumn('purchase_requisition_items', 'inventory_reorder_rule_id'));

        $this->owner();

        $this->get('/inventory/reorder')
            ->assertOk()
            ->assertSee(__('operations.reorder.title'));

        $this->get('/inventory')
            ->assertOk()
            ->assertSee(__('operations.reorder.title'));
    }

    public function test_weighted_average_stock_drives_low_stock_suggestion_and_value(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $product = $this->product();

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '10.0000',
            'unit_cost' => '10.0000',
            'occurred_at' => now()->subMinute(),
        ]);
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => '10.0000',
            'unit_cost' => '20.0000',
            'occurred_at' => now(),
        ]);

        $this->post('/inventory/reorder', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'reorder_point' => '25.0000',
            'target_stock' => '40.0000',
            'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $rule = InventoryReorderRule::firstOrFail();
        $row = app(InventoryReorderService::class)->row($rule);

        $this->assertSame('20.0000', $row['current_quantity']);
        $this->assertSame('15.0000', $row['average_unit_cost']);
        $this->assertSame('15.0000', $row['planning_unit_cost']);
        $this->assertSame('low', $row['status']);
        $this->assertSame('20.0000', $row['suggested_quantity']);
        $this->assertSame('300.0000', $row['suggested_value']);
    }

    public function test_zero_stock_uses_latest_known_warehouse_cost_for_planning(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'ZERO', 'name' => 'Zero Stock', 'is_active' => true]);
        $product = $this->product('Zero Product', 'ZERO-1');

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '5.0000',
            'unit_cost' => '12.0000',
            'occurred_at' => now()->subMinute(),
        ]);
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'sale',
            'quantity' => '-5.0000',
            'unit_cost' => '12.0000',
            'occurred_at' => now(),
        ]);

        $rule = app(InventoryReorderService::class)->saveRule(
            $warehouse,
            $product,
            null,
            '2.0000',
            '10.0000',
        );

        $row = app(InventoryReorderService::class)->row($rule);

        $this->assertSame('0.0000', $row['current_quantity']);
        $this->assertSame('0.0000', $row['average_unit_cost']);
        $this->assertSame('12.0000', $row['planning_unit_cost']);
        $this->assertSame('out_of_stock', $row['status']);
        $this->assertSame('10.0000', $row['suggested_quantity']);
        $this->assertSame('120.0000', $row['suggested_value']);
    }

    public function test_saving_same_rule_updates_without_creating_duplicate(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'UPD', 'name' => 'Update', 'is_active' => true]);
        $product = $this->product('Update Product', 'UPD-1');

        foreach ([['5', '15'], ['8', '20']] as [$point, $target]) {
            $this->post('/inventory/reorder', [
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'reorder_point' => $point,
                'target_stock' => $target,
                'is_active' => 1,
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(1, InventoryReorderRule::count());
        $rule = InventoryReorderRule::firstOrFail();
        $this->assertSame('8.0000', $rule->reorder_point);
        $this->assertSame('20.0000', $rule->target_stock);
    }

    public function test_variant_rules_are_isolated_and_variant_is_required_when_product_has_variants(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'VAR', 'name' => 'Variants', 'is_active' => true]);
        $product = $this->product('Variant Product', 'VAR-BASE');
        $large = ProductVariant::create(['product_id' => $product->id, 'name' => 'Large', 'sku' => 'VAR-L', 'is_active' => true]);
        $small = ProductVariant::create(['product_id' => $product->id, 'name' => 'Small', 'sku' => 'VAR-S', 'is_active' => true]);

        $this->post('/inventory/reorder', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'reorder_point' => '2',
            'target_stock' => '6',
        ])->assertSessionHasErrors('reorder');

        foreach ([$large, $small] as $variant) {
            $this->post('/inventory/reorder', [
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'reorder_point' => '2',
                'target_stock' => '6',
                'is_active' => 1,
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        $this->assertSame(2, InventoryReorderRule::count());
        $this->assertSame([$large->id, $small->id], InventoryReorderRule::query()->orderBy('product_variant_id')->pluck('product_variant_id')->all());
    }

    public function test_inactive_rule_has_no_replenishment_suggestion_and_can_be_reenabled(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'OFF', 'name' => 'Inactive', 'is_active' => true]);
        $product = $this->product('Inactive Product', 'OFF-1');

        $rule = app(InventoryReorderService::class)->saveRule($warehouse, $product, null, '5.0000', '10.0000', false);
        $row = app(InventoryReorderService::class)->row($rule);

        $this->assertSame('inactive', $row['status']);
        $this->assertSame('0.0000', $row['suggested_quantity']);

        $this->patch('/inventory/reorder/'.$rule->id.'/active', ['is_active' => 1])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($rule->fresh()->is_active);
        $this->assertSame('out_of_stock', app(InventoryReorderService::class)->row($rule->fresh())['status']);
    }

    public function test_target_stock_must_exceed_reorder_point(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'VAL', 'name' => 'Validation', 'is_active' => true]);
        $product = $this->product('Validation Product', 'VAL-1');

        $this->post('/inventory/reorder', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'reorder_point' => '10',
            'target_stock' => '10',
        ])->assertSessionHasErrors('target_stock');

        $this->assertDatabaseCount('inventory_reorder_rules', 0);
    }

    public function test_shortage_creates_warehouse_requisition_and_prevents_duplicate_replenishment(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'PR', 'name' => 'PR Warehouse', 'is_active' => true]);
        $product = $this->product('PR Product', 'PR-1');

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '2.0000',
            'unit_cost' => '10.0000',
            'occurred_at' => now(),
        ]);

        $rule = app(InventoryReorderService::class)->saveRule(
            $warehouse,
            $product,
            null,
            '5.0000',
            '10.0000',
        );

        $this->post(route('inventory.reorder.requisition'), [
            'rule_ids' => [$rule->id],
            'needed_by' => '2026-10-02',
        ])->assertRedirect(route('purchasing.requisitions.index'))
            ->assertSessionHasNoErrors();

        $requisition = PurchaseRequisition::with('items')->firstOrFail();
        $this->assertSame($warehouse->id, $requisition->warehouse_id);
        $this->assertSame('8.0000', $requisition->items->first()->quantity);
        $this->assertSame('10.0000', $requisition->items->first()->estimated_unit_cost);
        $this->assertSame($rule->id, $requisition->items->first()->inventory_reorder_rule_id);

        $row = app(InventoryReorderService::class)->row($rule->fresh());
        $this->assertSame('8.0000', $row['pipeline_quantity']);
        $this->assertSame('10.0000', $row['projected_quantity']);
        $this->assertSame('0.0000', $row['suggested_quantity']);
        $this->assertSame('replenishing', $row['status']);

        $this->post(route('inventory.reorder.requisition'), [
            'rule_ids' => [$rule->id],
        ])->assertSessionHasErrors('reorder');

        $this->assertSame(1, PurchaseRequisition::count());
    }

    public function test_partial_and_final_po_receipts_update_replenishment_pipeline(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'PIPE', 'name' => 'Pipeline Warehouse', 'is_active' => true]);
        $product = $this->product('Pipeline Product', 'PIPE-1');

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '2.0000',
            'unit_cost' => '10.0000',
            'occurred_at' => now(),
        ]);

        $rule = app(InventoryReorderService::class)->saveRule(
            $warehouse,
            $product,
            null,
            '5.0000',
            '10.0000',
        );

        $this->post(route('inventory.reorder.requisition'), [
            'rule_ids' => [$rule->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $requisition = PurchaseRequisition::firstOrFail();
        $supplier = Supplier::create([
            'code' => 'SUP-PIPE',
            'name' => 'Pipeline Supplier',
            'is_active' => true,
        ]);

        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'purchase_requisition_id' => $requisition->id,
            'number' => 'PO-PIPE-1',
            'status' => 'ordered',
            'ap_recognition' => 'invoice',
            'order_date' => '2026-09-28',
            'expected_date' => '2026-10-02',
            'subtotal' => '80.0000',
            'total' => '80.0000',
        ]);

        $item = $order->items()->create([
            'product_id' => $product->id,
            'quantity' => '8.0000',
            'unit_cost' => '10.0000',
            'line_total' => '80.0000',
        ]);

        $beforeReceipt = app(InventoryReorderService::class)->row($rule->fresh());
        $this->assertSame('8.0000', $beforeReceipt['pipeline_quantity']);
        $this->assertSame('replenishing', $beforeReceipt['status']);

        $wrongWarehouse = Warehouse::create([
            'code' => 'WRONG',
            'name' => 'Wrong Warehouse',
            'is_active' => true,
        ]);

        $this->post(route('purchasing.orders.receive', $order), [
            'warehouse_id' => $wrongWarehouse->id,
            'receipt_date' => '2026-09-29',
            'items' => [
                ['purchase_order_item_id' => $item->id, 'quantity' => '3.0000'],
            ],
        ])->assertSessionHasErrors('warehouse_id');

        $this->assertSame('ordered', $order->fresh()->status);
        $this->assertSame(2.0, (float) StockMovement::where('product_id', $product->id)->sum('quantity'));

        $this->post(route('purchasing.orders.receive', $order), [
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-29',
            'items' => [
                ['purchase_order_item_id' => $item->id, 'quantity' => '3.0000'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $afterPartial = app(InventoryReorderService::class)->row($rule->fresh());
        $this->assertSame('5.0000', $afterPartial['current_quantity']);
        $this->assertSame('5.0000', $afterPartial['pipeline_quantity']);
        $this->assertSame('10.0000', $afterPartial['projected_quantity']);
        $this->assertSame('0.0000', $afterPartial['suggested_quantity']);
        $this->assertSame('replenishing', $afterPartial['status']);

        $this->post(route('purchasing.orders.receive', $order->fresh()), [
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-30',
            'items' => [
                ['purchase_order_item_id' => $item->id, 'quantity' => '5.0000'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $afterComplete = app(InventoryReorderService::class)->row($rule->fresh());
        $this->assertSame('10.0000', $afterComplete['current_quantity']);
        $this->assertSame('0.0000', $afterComplete['pipeline_quantity']);
        $this->assertSame('10.0000', $afterComplete['projected_quantity']);
        $this->assertSame('0.0000', $afterComplete['suggested_quantity']);
        $this->assertSame('ok', $afterComplete['status']);
    }

    public function test_cross_business_rule_is_not_accessible(): void
    {
        [$ownerA, $businessA] = $this->owner('Business A');
        $warehouse = Warehouse::create(['code' => 'A', 'name' => 'A', 'is_active' => true]);
        $product = $this->product('A Product', 'A-1');
        $rule = app(InventoryReorderService::class)->saveRule($warehouse, $product, null, '2', '5');

        [$ownerB, $businessB] = $this->owner('Business B');
        $this->actIn($ownerB, $businessB);

        $this->patch('/inventory/reorder/'.$rule->id.'/active', ['is_active' => 0])->assertNotFound();
        $this->get('/inventory/reorder')->assertOk()->assertDontSee('A Product');

        $this->actIn($ownerA, $businessA);
        $this->assertTrue($rule->fresh()->is_active);
    }
}
