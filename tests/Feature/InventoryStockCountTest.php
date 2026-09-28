<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\InventoryCount;
use App\Models\JournalEntry;
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

class InventoryStockCountTest extends TestCase
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

    private function owner(string $businessName = 'Stock Count Business'): array
    {
        $user = User::create([
            'name' => 'Stock Owner',
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
            'sale_price' => '100.0000',
        ]);
    }

    public function test_stock_count_schema_and_numbering_are_registered(): void
    {
        $this->assertTrue(Schema::hasTable('inventory_counts'));
        $this->assertTrue(Schema::hasTable('inventory_count_items'));
        $this->assertSame('STK', config('numbering.prefixes.inventory_count'));
        $this->assertArrayHasKey('inventory_count_prefix', config('settings.definitions.numbering'));
    }

    public function test_stock_count_posts_weighted_average_shrinkage_and_gain_with_balanced_accounting(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);
        $paper = $this->product('Paper', 'PAPER');
        $ink = $this->product('Ink', 'INK');

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $paper->id,
            'type' => 'opening',
            'quantity' => '10.0000',
            'unit_cost' => '10.0000',
            'occurred_at' => now()->subMinutes(2),
        ]);
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $paper->id,
            'type' => 'purchase',
            'quantity' => '10.0000',
            'unit_cost' => '20.0000',
            'occurred_at' => now()->subMinute(),
        ]);
        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $ink->id,
            'type' => 'opening',
            'quantity' => '5.0000',
            'unit_cost' => '8.0000',
            'occurred_at' => now(),
        ]);

        $this->post('/inventory/counts', [
            'warehouse_id' => $warehouse->id,
            'count_date' => '2026-09-28',
        ])->assertRedirect();

        $count = InventoryCount::with('items')->firstOrFail();
        $this->assertSame('STK-000001', $count->number);
        $this->assertCount(2, $count->items);

        $paperLine = $count->items->firstWhere('product_id', $paper->id);
        $inkLine = $count->items->firstWhere('product_id', $ink->id);

        $this->assertSame('20.0000', $paperLine->expected_quantity);
        $this->assertSame('15.0000', $paperLine->unit_cost);
        $this->assertSame('5.0000', $inkLine->expected_quantity);
        $this->assertSame('8.0000', $inkLine->unit_cost);

        $this->patch("/inventory/counts/{$count->id}/items/{$paperLine->id}", [
            'counted_quantity' => '18.0000',
        ])->assertRedirect();
        $this->patch("/inventory/counts/{$count->id}/items/{$inkLine->id}", [
            'counted_quantity' => '7.0000',
        ])->assertRedirect();

        $this->post("/inventory/counts/{$count->id}/submit")->assertRedirect();
        $this->post("/inventory/counts/{$count->id}/post")->assertRedirect()->assertSessionHasNoErrors();

        $count->refresh();
        $this->assertSame('posted', $count->status);
        $this->assertSame('16.0000', $count->total_positive_variance_value);
        $this->assertSame('30.0000', $count->total_negative_variance_value);
        $this->assertSame(18.0, (float) StockMovement::where('product_id', $paper->id)->sum('quantity'));
        $this->assertSame(7.0, (float) StockMovement::where('product_id', $ink->id)->sum('quantity'));

        $this->assertSame(2, StockMovement::where('type', 'stock_count_adjustment')->count());

        $journal = JournalEntry::query()
            ->where('source_type', InventoryCount::class)
            ->where('source_id', $count->id)
            ->with('lines.account')
            ->firstOrFail();

        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account?->code === 'AUTO-INVENTORY-SHRINKAGE'
                && $line->debit === '30.0000',
        ));
        $this->assertTrue($journal->lines->contains(
            fn ($line) => $line->account?->code === 'AUTO-INVENTORY-GAIN'
                && $line->credit === '16.0000',
        ));
        $this->assertEquals(
            $journal->lines->sum(fn ($line) => (float) $line->debit),
            $journal->lines->sum(fn ($line) => (float) $line->credit),
        );
    }

    public function test_stock_count_refuses_stale_posting_after_inventory_changes(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'STALE', 'name' => 'Stale', 'is_active' => true]);
        $product = $this->product('Counted Product', 'COUNTED');

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '10.0000',
            'unit_cost' => '5.0000',
            'occurred_at' => now(),
        ]);
        $this->post('/inventory/counts', [
            'warehouse_id' => $warehouse->id,
            'count_date' => '2026-09-28',
        ]);

        $count = InventoryCount::with('items')->firstOrFail();
        $line = $count->items->first();

        $this->patch("/inventory/counts/{$count->id}/items/{$line->id}", [
            'counted_quantity' => '9.0000',
        ]);
        $this->post("/inventory/counts/{$count->id}/submit");

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'sale',
            'quantity' => '-1.0000',
            'unit_cost' => '5.0000',
            'occurred_at' => now()->addSecond(),
        ]);

        $this->post("/inventory/counts/{$count->id}/post")
            ->assertRedirect()
            ->assertSessionHasErrors('stock_count');

        $this->assertSame('submitted', $count->fresh()->status);
        $this->assertSame(0, StockMovement::where('type', 'stock_count_adjustment')->count());
        $this->assertSame(0, JournalEntry::where('source_type', InventoryCount::class)->count());
    }

    public function test_newly_discovered_stock_posts_with_supplied_cost_basis(): void
    {
        $this->owner();
        $warehouse = Warehouse::create(['code' => 'FOUND', 'name' => 'Found Stock', 'is_active' => true]);
        $product = $this->product('Found Product', 'FOUND-1');

        $this->post('/inventory/counts', [
            'warehouse_id' => $warehouse->id,
            'count_date' => '2026-09-28',
        ])->assertRedirect();

        $count = InventoryCount::firstOrFail();

        $this->post("/inventory/counts/{$count->id}/items", [
            'product_id' => $product->id,
            'unit_cost' => '12.0000',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $line = $count->items()->firstOrFail();
        $this->patch("/inventory/counts/{$count->id}/items/{$line->id}", [
            'counted_quantity' => '3.0000',
            'unit_cost' => '12.0000',
        ])->assertRedirect();

        $this->post("/inventory/counts/{$count->id}/submit")->assertRedirect();
        $this->post("/inventory/counts/{$count->id}/post")->assertRedirect()->assertSessionHasNoErrors();

        $count->refresh();
        $this->assertSame('posted', $count->status);
        $this->assertSame('36.0000', $count->total_positive_variance_value);

        $adjustment = StockMovement::where('type', 'stock_count_adjustment')->firstOrFail();
        $this->assertSame('3.0000', $adjustment->quantity);
        $this->assertSame('12.0000', $adjustment->unit_cost);
    }

    public function test_cross_business_stock_count_is_not_visible(): void
    {
        [$ownerA, $businessA] = $this->owner('Business A');
        $warehouse = Warehouse::create(['code' => 'A', 'name' => 'A', 'is_active' => true]);

        $this->post('/inventory/counts', [
            'warehouse_id' => $warehouse->id,
            'count_date' => '2026-09-28',
        ]);
        $count = InventoryCount::firstOrFail();

        [$ownerB, $businessB] = $this->owner('Business B');
        $this->actingAs($ownerB);
        session([config('business.context.session_key') => $businessB->id]);
        $this->app->forgetScopedInstances();

        $this->get("/inventory/counts/{$count->id}")->assertNotFound();

        $this->actingAs($ownerA);
        session([config('business.context.session_key') => $businessA->id]);
        $this->app->forgetScopedInstances();

        $this->get("/inventory/counts/{$count->id}")->assertOk();
    }
}
