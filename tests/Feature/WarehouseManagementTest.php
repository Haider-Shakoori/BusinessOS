<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\PosRegister;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseManagementTest extends TestCase
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

    private function owner(string $businessName = 'Warehouse Business'): array
    {
        $user = User::create([
            'name' => 'Warehouse Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create(['name' => $businessName]);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (['dashboard', 'settings', 'products', 'inventory', 'pos'] as $module) {
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

    public function test_warehouse_schema_and_management_workspace_are_available(): void
    {
        $this->assertTrue(Schema::hasColumn('warehouses', 'is_default'));

        $this->owner();

        $this->get(route('inventory.warehouses.index'))
            ->assertOk()
            ->assertSee(__('operations.warehouses.title'));

        $this->get(route('inventory.index'))
            ->assertOk()
            ->assertSee(__('operations.warehouses.title'));
    }

    public function test_first_warehouse_becomes_default_and_default_can_be_switched(): void
    {
        $this->owner();

        $this->post(route('inventory.warehouses.store'), [
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
        ])->assertRedirect(route('inventory.warehouses.index'))
            ->assertSessionHasNoErrors();

        $main = Warehouse::firstOrFail();
        $this->assertTrue($main->is_default);
        $this->assertTrue($main->is_active);

        $this->post(route('inventory.warehouses.store'), [
            'code' => 'WEST',
            'name' => 'West Warehouse',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $west = Warehouse::query()->where('code', 'WEST')->firstOrFail();
        $this->assertFalse($west->is_default);

        $this->post(route('inventory.warehouses.default', $west))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue($west->fresh()->is_default);
        $this->assertFalse($main->fresh()->is_default);
        $this->assertSame(1, Warehouse::query()->where('is_default', true)->count());
    }

    public function test_default_warehouse_cannot_be_deactivated_or_deleted(): void
    {
        $this->owner();

        $this->post(route('inventory.warehouses.store'), [
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
        ])->assertRedirect();

        $warehouse = Warehouse::firstOrFail();

        $this->patch(route('inventory.warehouses.active', $warehouse), ['is_active' => 0])
            ->assertRedirect()
            ->assertSessionHasErrors('warehouse');

        $this->delete(route('inventory.warehouses.destroy', $warehouse))
            ->assertRedirect()
            ->assertSessionHasErrors('warehouse');

        $this->assertTrue($warehouse->fresh()->is_active);
        $this->assertTrue($warehouse->fresh()->is_default);
    }

    public function test_unused_non_default_warehouse_can_be_deactivated_reactivated_and_deleted(): void
    {
        $this->owner();

        $this->post(route('inventory.warehouses.store'), ['code' => 'A', 'name' => 'A'])->assertRedirect();
        $this->post(route('inventory.warehouses.store'), ['code' => 'B', 'name' => 'B'])->assertRedirect();

        $a = Warehouse::query()->where('code', 'A')->firstOrFail();
        $b = Warehouse::query()->where('code', 'B')->firstOrFail();

        $this->post(route('inventory.warehouses.default', $b))->assertRedirect();

        $this->patch(route('inventory.warehouses.active', $a), ['is_active' => 0])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertFalse($a->fresh()->is_active);

        $this->patch(route('inventory.warehouses.active', $a), ['is_active' => 1])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertTrue($a->fresh()->is_active);

        $this->delete(route('inventory.warehouses.destroy', $a))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('warehouses', ['id' => $a->id]);
    }

    public function test_warehouse_with_operational_history_cannot_be_deleted(): void
    {
        $this->owner();

        $this->post(route('inventory.warehouses.store'), ['code' => 'MAIN', 'name' => 'Main'])->assertRedirect();
        $this->post(route('inventory.warehouses.store'), ['code' => 'USED', 'name' => 'Used'])->assertRedirect();

        $warehouse = Warehouse::query()->where('code', 'USED')->firstOrFail();
        $product = Product::create([
            'type' => 'product',
            'name' => 'Warehouse Product',
            'sku' => 'WH-PROD',
            'sale_price' => '10.0000',
        ]);

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '1.0000',
            'unit_cost' => '5.0000',
            'occurred_at' => now(),
        ]);

        $this->delete(route('inventory.warehouses.destroy', $warehouse))
            ->assertRedirect()
            ->assertSessionHasErrors('warehouse');

        $this->assertNull($warehouse->fresh()->deleted_at);
    }

    public function test_pos_register_without_warehouse_uses_default_warehouse(): void
    {
        $this->owner();

        $this->post(route('inventory.warehouses.store'), ['code' => 'A', 'name' => 'A'])->assertRedirect();
        $this->post(route('inventory.warehouses.store'), [
            'code' => 'B',
            'name' => 'B',
            'is_default' => 1,
        ])->assertRedirect();

        $default = Warehouse::query()->where('is_default', true)->firstOrFail();

        $this->post(route('pos.registers.store'), [
            'code' => 'POS-1',
            'name' => 'Main Counter',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($default->id, PosRegister::firstOrFail()->warehouse_id);
    }

    public function test_pos_register_creates_default_warehouse_when_none_exists(): void
    {
        $this->owner();

        $this->post(route('pos.registers.store'), [
            'code' => 'POS-1',
            'name' => 'Main Counter',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $warehouse = Warehouse::firstOrFail();
        $this->assertTrue($warehouse->is_default);
        $this->assertTrue($warehouse->is_active);
        $this->assertSame($warehouse->id, PosRegister::firstOrFail()->warehouse_id);
    }

    public function test_cross_business_warehouse_management_is_blocked(): void
    {
        [$ownerA, $businessA] = $this->owner('Business A');
        $this->post(route('inventory.warehouses.store'), ['code' => 'A', 'name' => 'A'])->assertRedirect();
        $warehouse = Warehouse::firstOrFail();

        [$ownerB, $businessB] = $this->owner('Business B');
        $this->actIn($ownerB, $businessB);

        $this->put('/inventory/warehouses/'.$warehouse->id, [
            'code' => 'HACK',
            'name' => 'Hacked',
        ])->assertNotFound();

        $this->post('/inventory/warehouses/'.$warehouse->id.'/default')->assertNotFound();

        $this->actIn($ownerA, $businessA);
        $this->assertSame('A', $warehouse->fresh()->code);
        $this->assertTrue($warehouse->fresh()->is_default);
    }
}
