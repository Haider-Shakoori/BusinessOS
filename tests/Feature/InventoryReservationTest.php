<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\InventoryReorderRule;
use App\Models\InventoryReservation;
use App\Models\PosRegister;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseTransfer;
use App\Services\InventoryAvailabilityService;
use App\Services\InventoryReorderService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryReservationTest extends TestCase
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

    private function owner(string $businessName = 'Reservation Business'): array
    {
        $user = User::create([
            'name' => 'Reservation Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create(['name' => $businessName]);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (['dashboard', 'settings', 'products', 'inventory', 'accounting', 'pos', 'manufacturing'] as $module) {
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

    private function warehouse(string $code = 'MAIN'): array
    {
        $this->post(route('inventory.warehouses.store'), [
            'code' => $code,
            'name' => $code.' Warehouse',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $warehouse = Warehouse::query()->where('code', $code)->firstOrFail();
        $location = WarehouseLocation::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('is_default', true)
            ->firstOrFail();

        return [$warehouse, $location];
    }

    private function product(string $sku = 'ATP-PROD'): Product
    {
        return Product::create([
            'type' => 'product',
            'name' => 'ATP Product',
            'sku' => $sku,
            'sale_price' => '20.0000',
        ]);
    }

    private function opening(Warehouse $warehouse, WarehouseLocation $location, Product $product, string $quantity): void
    {
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => $quantity,
            'unit_cost' => '5.0000',
            'occurred_at' => now(),
        ]);
    }

    public function test_reservation_schema_workspace_and_atp_math_are_available(): void
    {
        $this->assertTrue(Schema::hasTable('inventory_reservations'));
        $this->assertTrue(Schema::hasColumn('pos_registers', 'location_id'));

        $this->owner();
        [$warehouse, $location] = $this->warehouse();
        $product = $this->product();
        $this->opening($warehouse, $location, $product, '10.0000');

        $this->get(route('inventory.reservations.index'))
            ->assertOk()
            ->assertSee(__('operations.reservations.title'));

        $this->post(route('inventory.reservations.store'), [
            'location_id' => $location->id,
            'product_id' => $product->id,
            'quantity' => '4.0000',
            'note' => 'Committed customer stock',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $reservation = InventoryReservation::firstOrFail();
        $this->assertSame('active', $reservation->status);
        $this->assertSame('4.0000', $reservation->quantity);

        $snapshot = app(InventoryAvailabilityService::class)->snapshot(
            $warehouse->id,
            $product->id,
            null,
            $location->id,
        );

        $this->assertSame('10.0000', $snapshot['on_hand']);
        $this->assertSame('4.0000', $snapshot['reserved']);
        $this->assertSame('6.0000', $snapshot['available']);
        $this->assertSame(
            10.0,
            (float) StockMovement::where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->sum('quantity'),
        );
    }

    public function test_over_reservation_is_blocked_and_release_restores_available_stock(): void
    {
        $this->owner();
        [$warehouse, $location] = $this->warehouse();
        $product = $this->product('ATP-LIMIT');
        $this->opening($warehouse, $location, $product, '5.0000');

        $this->post(route('inventory.reservations.store'), [
            'location_id' => $location->id,
            'product_id' => $product->id,
            'quantity' => '4.0000',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post(route('inventory.reservations.store'), [
            'location_id' => $location->id,
            'product_id' => $product->id,
            'quantity' => '2.0000',
        ])->assertRedirect()->assertSessionHasErrors('reservation');

        $this->assertSame(1, InventoryReservation::count());

        $reservation = InventoryReservation::firstOrFail();
        $this->post(route('inventory.reservations.release', $reservation))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('released', $reservation->fresh()->status);

        $snapshot = app(InventoryAvailabilityService::class)->snapshot(
            $warehouse->id,
            $product->id,
            null,
            $location->id,
        );

        $this->assertSame('0.0000', $snapshot['reserved']);
        $this->assertSame('5.0000', $snapshot['available']);
    }

    public function test_expired_reservations_stop_reducing_atp_and_command_marks_them_expired(): void
    {
        $this->owner();
        [$warehouse, $location] = $this->warehouse();
        $product = $this->product('ATP-EXP');
        $this->opening($warehouse, $location, $product, '5.0000');

        $reservation = InventoryReservation::create([
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'product_id' => $product->id,
            'quantity' => '3.0000',
            'status' => 'active',
            'expires_at' => now()->subMinute(),
        ]);

        $snapshot = app(InventoryAvailabilityService::class)->snapshot(
            $warehouse->id,
            $product->id,
            null,
            $location->id,
        );

        $this->assertSame('0.0000', $snapshot['reserved']);
        $this->assertSame('5.0000', $snapshot['available']);

        $this->artisan('inventory:reservations-expire')->assertExitCode(0);
        $this->assertSame('expired', $reservation->fresh()->status);
    }

    public function test_reorder_planning_uses_available_to_promise_instead_of_raw_on_hand(): void
    {
        $this->owner();
        [$warehouse, $location] = $this->warehouse();
        $product = $this->product('ATP-REORDER');
        $this->opening($warehouse, $location, $product, '10.0000');

        $this->post(route('inventory.reservations.store'), [
            'location_id' => $location->id,
            'product_id' => $product->id,
            'quantity' => '8.0000',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post(route('inventory.reorder.store'), [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'reorder_point' => '5.0000',
            'target_stock' => '12.0000',
            'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $rule = InventoryReorderRule::firstOrFail();
        $row = app(InventoryReorderService::class)->row($rule);

        $this->assertSame('10.0000', $row['current_quantity']);
        $this->assertSame('8.0000', $row['reserved_quantity']);
        $this->assertSame('2.0000', $row['available_quantity']);
        $this->assertSame('2.0000', $row['projected_quantity']);
        $this->assertSame('10.0000', $row['suggested_quantity']);
        $this->assertSame('low', $row['status']);
    }

    public function test_manual_issue_cannot_consume_reserved_stock(): void
    {
        $this->owner();
        [$warehouse, $location] = $this->warehouse();
        $product = $this->product('ATP-ISSUE');
        $this->opening($warehouse, $location, $product, '10.0000');

        $this->post(route('inventory.reservations.store'), [
            'location_id' => $location->id,
            'product_id' => $product->id,
            'quantity' => '8.0000',
        ])->assertRedirect();

        $this->post(route('inventory.movements.store'), [
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'product_id' => $product->id,
            'type' => 'adjustment',
            'quantity' => '-3.0000',
        ])->assertRedirect()->assertSessionHasErrors('quantity');

        $this->post(route('inventory.movements.store'), [
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'product_id' => $product->id,
            'type' => 'adjustment',
            'quantity' => '-2.0000',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $snapshot = app(InventoryAvailabilityService::class)->snapshot(
            $warehouse->id,
            $product->id,
            null,
            $location->id,
        );

        $this->assertSame('8.0000', $snapshot['on_hand']);
        $this->assertSame('8.0000', $snapshot['reserved']);
        $this->assertSame('0.0000', $snapshot['available']);
    }

    public function test_warehouse_transfer_cannot_dispatch_reserved_stock(): void
    {
        $this->owner();
        [$source, $sourceLocation] = $this->warehouse('SRC');
        [$destination] = $this->warehouse('DST');
        $product = $this->product('ATP-TRANSFER');
        $this->opening($source, $sourceLocation, $product, '10.0000');

        $this->post(route('inventory.reservations.store'), [
            'location_id' => $sourceLocation->id,
            'product_id' => $product->id,
            'quantity' => '8.0000',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post(route('inventory.transfers.store'), [
            'source_warehouse_id' => $source->id,
            'destination_warehouse_id' => $destination->id,
            'product_id' => $product->id,
            'quantity' => '3.0000',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $transfer = WarehouseTransfer::firstOrFail();

        $this->post(route('inventory.transfers.dispatch', $transfer))
            ->assertRedirect()
            ->assertSessionHasErrors('transfer');

        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertSame(0, StockMovement::where('type', 'transfer_out')->count());
    }

    public function test_pos_register_is_location_bound_and_checkout_respects_atp(): void
    {
        $this->owner();
        [$warehouse, $main] = $this->warehouse();

        $this->post(route('inventory.locations.store'), [
            'warehouse_id' => $warehouse->id,
            'code' => 'POS-BIN',
            'name' => 'POS Bin',
            'type' => 'bin',
            'is_default' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $location = WarehouseLocation::query()->where('code', 'POS-BIN')->firstOrFail();
        $product = $this->product('ATP-POS');
        $this->opening($warehouse, $location, $product, '5.0000');

        $this->post(route('inventory.reservations.store'), [
            'location_id' => $location->id,
            'product_id' => $product->id,
            'quantity' => '2.0000',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->post(route('pos.registers.store'), [
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'code' => 'POS-ATP',
            'name' => 'ATP Counter',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $register = PosRegister::firstOrFail();
        $this->assertSame($location->id, $register->location_id);

        $this->post(route('pos.shifts.open', $register), [
            'opening_cash' => '0',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $shift = PosShift::query()->where('pos_register_id', $register->id)->firstOrFail();

        $this->post(route('pos.checkout', $shift), [
            'items' => json_encode([[
                'product_id' => $product->id,
                'quantity' => 4,
            ]]),
            'payment_method' => 'cash',
            'amount_tendered' => '100.0000',
        ])->assertRedirect()->assertSessionHasErrors('cart');

        $this->assertSame(0, PosSale::count());

        $this->post(route('pos.checkout', $shift), [
            'items' => json_encode([[
                'product_id' => $product->id,
                'quantity' => 3,
            ]]),
            'payment_method' => 'cash',
            'amount_tendered' => '100.0000',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $sale = PosSale::firstOrFail();
        $movement = StockMovement::query()
            ->where('reference_type', PosSale::class)
            ->where('reference_id', $sale->id)
            ->where('type', 'sale')
            ->firstOrFail();

        $this->assertSame($location->id, $movement->location_id);
        $this->assertSame(
            0.0,
            (float) StockMovement::where('location_id', $main->id)->where('product_id', $product->id)->sum('quantity'),
        );

        $snapshot = app(InventoryAvailabilityService::class)->snapshot(
            $warehouse->id,
            $product->id,
            null,
            $location->id,
        );

        $this->assertSame('2.0000', $snapshot['on_hand']);
        $this->assertSame('2.0000', $snapshot['reserved']);
        $this->assertSame('0.0000', $snapshot['available']);
    }

    public function test_cross_business_reservation_is_not_visible_or_releasable(): void
    {
        [$ownerA, $businessA] = $this->owner('Reservation A');
        [$warehouse, $location] = $this->warehouse('A');
        $product = $this->product('ATP-TENANT');
        $this->opening($warehouse, $location, $product, '5.0000');

        $this->post(route('inventory.reservations.store'), [
            'location_id' => $location->id,
            'product_id' => $product->id,
            'quantity' => '1.0000',
            'note' => 'Tenant A private reservation',
        ])->assertRedirect();

        $reservation = InventoryReservation::firstOrFail();

        [$ownerB, $businessB] = $this->owner('Reservation B');
        $this->actIn($ownerB, $businessB);

        $this->get(route('inventory.reservations.index'))
            ->assertOk()
            ->assertDontSee('Tenant A private reservation');

        $this->post('/inventory/reservations/'.$reservation->id.'/release')
            ->assertNotFound();

        $this->actIn($ownerA, $businessA);
        $this->assertSame('active', $reservation->fresh()->status);
    }
}
