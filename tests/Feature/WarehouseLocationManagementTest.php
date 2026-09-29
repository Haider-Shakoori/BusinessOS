<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\InventoryCount;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseTransfer;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseLocationManagementTest extends TestCase
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

    private function owner(string $businessName = 'Location Business'): array
    {
        $user = User::create([
            'name' => 'Location Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create(['name' => $businessName]);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (['dashboard', 'settings', 'products', 'inventory', 'accounting'] as $module) {
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

    private function product(string $sku = 'LOC-PROD'): Product
    {
        return Product::create([
            'type' => 'product',
            'name' => 'Location Product',
            'sku' => $sku,
            'sale_price' => '100.0000',
        ]);
    }

    public function test_location_schema_and_default_location_are_provisioned(): void
    {
        $this->assertTrue(Schema::hasTable('warehouse_locations'));
        $this->assertTrue(Schema::hasColumn('stock_movements', 'location_id'));
        $this->assertTrue(Schema::hasColumn('inventory_counts', 'location_id'));
        $this->assertTrue(Schema::hasColumn('warehouse_transfer_items', 'source_location_id'));

        $this->owner();

        $this->post(route('inventory.warehouses.store'), [
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $warehouse = Warehouse::firstOrFail();
        $location = WarehouseLocation::firstOrFail();

        $this->assertSame($warehouse->id, $location->warehouse_id);
        $this->assertSame('MAIN', $location->code);
        $this->assertTrue($location->is_default);
        $this->assertTrue($location->is_active);

        $this->get(route('inventory.locations.index'))
            ->assertOk()
            ->assertSee(__('operations.locations.title'));
    }

    public function test_stock_movement_without_location_uses_warehouse_default(): void
    {
        $this->owner();

        $this->post(route('inventory.warehouses.store'), [
            'code' => 'AUTO',
            'name' => 'Automatic Location Warehouse',
        ])->assertRedirect();

        $warehouse = Warehouse::firstOrFail();
        $location = WarehouseLocation::where('warehouse_id', $warehouse->id)->where('is_default', true)->firstOrFail();
        $product = $this->product();

        $movement = StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '5.0000',
            'unit_cost' => '10.0000',
            'occurred_at' => now(),
        ]);

        $this->assertSame($location->id, $movement->location_id);
    }

    public function test_same_warehouse_transfer_moves_stock_between_locations_without_changing_warehouse_total(): void
    {
        $this->owner();

        $this->post(route('inventory.warehouses.store'), [
            'code' => 'W1',
            'name' => 'Warehouse One',
        ])->assertRedirect();

        $warehouse = Warehouse::firstOrFail();
        $main = WarehouseLocation::where('warehouse_id', $warehouse->id)->where('is_default', true)->firstOrFail();

        $this->post(route('inventory.locations.store'), [
            'warehouse_id' => $warehouse->id,
            'code' => 'R1-B1',
            'name' => 'Rack 1 Bin 1',
            'type' => 'bin',
            'is_default' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $bin = WarehouseLocation::where('warehouse_id', $warehouse->id)->where('code', 'R1-B1')->firstOrFail();
        $product = $this->product('MOVE-PROD');

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'location_id' => $main->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '10.0000',
            'unit_cost' => '8.0000',
            'occurred_at' => now(),
        ]);

        $this->post(route('inventory.transfers.store'), [
            'source_warehouse_id' => $warehouse->id,
            'destination_warehouse_id' => $warehouse->id,
            'source_location_id' => $main->id,
            'destination_location_id' => $bin->id,
            'product_id' => $product->id,
            'quantity' => '4.0000',
            'note' => 'Internal bin relocation',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $transfer = WarehouseTransfer::with('items')->firstOrFail();
        $this->post(route('inventory.transfers.dispatch', $transfer))
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->post(route('inventory.transfers.receive', $transfer))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            10.0,
            (float) StockMovement::where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->sum('quantity'),
        );
        $this->assertSame(
            6.0,
            (float) StockMovement::where('location_id', $main->id)
                ->where('product_id', $product->id)
                ->sum('quantity'),
        );
        $this->assertSame(
            4.0,
            (float) StockMovement::where('location_id', $bin->id)
                ->where('product_id', $product->id)
                ->sum('quantity'),
        );

        $item = $transfer->fresh()->items()->firstOrFail();
        $this->assertSame($main->id, $item->source_location_id);
        $this->assertSame($bin->id, $item->destination_location_id);
    }

    public function test_stock_count_is_scoped_to_selected_location_and_posts_adjustment_there(): void
    {
        $this->owner();

        $this->post(route('inventory.warehouses.store'), [
            'code' => 'CNT',
            'name' => 'Count Warehouse',
        ])->assertRedirect();

        $warehouse = Warehouse::firstOrFail();
        $main = WarehouseLocation::where('warehouse_id', $warehouse->id)->where('is_default', true)->firstOrFail();

        $this->post(route('inventory.locations.store'), [
            'warehouse_id' => $warehouse->id,
            'code' => 'BIN-2',
            'name' => 'Bin 2',
            'type' => 'bin',
            'is_default' => 0,
        ])->assertRedirect();

        $bin = WarehouseLocation::where('warehouse_id', $warehouse->id)->where('code', 'BIN-2')->firstOrFail();
        $product = $this->product('COUNT-LOC');

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'location_id' => $main->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '7.0000',
            'unit_cost' => '5.0000',
            'occurred_at' => now()->subMinute(),
        ]);

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'location_id' => $bin->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '3.0000',
            'unit_cost' => '5.0000',
            'occurred_at' => now(),
        ]);

        $this->post(route('inventory.counts.store'), [
            'warehouse_id' => $warehouse->id,
            'location_id' => $bin->id,
            'count_date' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $count = InventoryCount::with('items')->firstOrFail();
        $this->assertSame($bin->id, $count->location_id);
        $line = $count->items->firstOrFail();
        $this->assertSame('3.0000', $line->expected_quantity);

        $this->patch(route('inventory.counts.items.update', [$count, $line]), [
            'counted_quantity' => '2.0000',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post(route('inventory.counts.submit', $count))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->post(route('inventory.counts.post', $count))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $adjustment = StockMovement::where('type', 'stock_count_adjustment')->firstOrFail();
        $this->assertSame($bin->id, $adjustment->location_id);
        $this->assertSame('-1.0000', $adjustment->quantity);

        $this->assertSame(
            7.0,
            (float) StockMovement::where('location_id', $main->id)
                ->where('product_id', $product->id)
                ->sum('quantity'),
        );
        $this->assertSame(
            2.0,
            (float) StockMovement::where('location_id', $bin->id)
                ->where('product_id', $product->id)
                ->sum('quantity'),
        );
    }

    public function test_default_or_used_location_cannot_be_deleted_and_cross_business_location_is_hidden(): void
    {
        [$ownerA, $businessA] = $this->owner('Location A');

        $this->post(route('inventory.warehouses.store'), [
            'code' => 'A',
            'name' => 'Warehouse A',
        ])->assertRedirect();

        $warehouse = Warehouse::firstOrFail();
        $default = WarehouseLocation::where('warehouse_id', $warehouse->id)->where('is_default', true)->firstOrFail();

        $this->delete(route('inventory.locations.destroy', $default))
            ->assertRedirect()
            ->assertSessionHasErrors('location');

        $this->post(route('inventory.locations.store'), [
            'warehouse_id' => $warehouse->id,
            'code' => 'USED',
            'name' => 'Used Bin',
            'type' => 'bin',
            'is_default' => 0,
        ])->assertRedirect();

        $used = WarehouseLocation::where('code', 'USED')->firstOrFail();
        $product = $this->product('USED-LOC');

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'location_id' => $used->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '1.0000',
            'unit_cost' => '1.0000',
            'occurred_at' => now(),
        ]);

        $this->delete(route('inventory.locations.destroy', $used))
            ->assertRedirect()
            ->assertSessionHasErrors('location');

        [$ownerB, $businessB] = $this->owner('Location B');
        $this->actIn($ownerB, $businessB);

        $this->put('/inventory/locations/'.$used->id, [
            'code' => 'HACK',
            'name' => 'Hacked',
            'type' => 'bin',
        ])->assertNotFound();

        $this->actIn($ownerA, $businessA);
        $this->assertSame('USED', $used->fresh()->code);
        $this->assertNull($used->fresh()->deleted_at);
    }
}
