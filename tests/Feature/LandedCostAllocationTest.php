<?php

namespace Tests\Feature;

use App\Models\AccountingPosting;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\GoodsReceipt;
use App\Models\LandedCost;
use App\Models\LandedCostAllocation;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\LandedCostService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class LandedCostAllocationTest extends TestCase
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

    public function test_landed_cost_schema_and_workspace_exist(): void
    {
        $this->assertTrue(Schema::hasTable('landed_costs'));
        $this->assertTrue(Schema::hasTable('landed_cost_charges'));
        $this->assertTrue(Schema::hasTable('landed_cost_allocations'));
        $this->assertTrue(Schema::hasColumn('goods_receipt_items', 'stock_movement_id'));

        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        $this->get(route('purchasing.landed-costs.index'))
            ->assertOk()
            ->assertSee('Landed costs');
    }

    public function test_value_allocation_posts_inventory_clearing_and_updates_purchase_valuation_cost(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$receipt, $movements] = $this->receipt([
            ['quantity' => '10.0000', 'unit_cost' => '10.0000'],
            ['quantity' => '10.0000', 'unit_cost' => '30.0000'],
        ]);

        $cost = app(LandedCostService::class)->create($receipt, [
            'cost_date' => '2026-09-30',
            'allocation_method' => 'value',
            'charges' => [
                ['category' => 'freight', 'description' => 'Truck freight', 'amount' => '30.0000'],
                ['category' => 'customs', 'description' => 'Border fee', 'amount' => '10.0000'],
            ],
        ], $user->id);

        $this->assertSame('LCT-000001', $cost->number);
        $this->assertSame('40.0000', $cost->total);
        $this->assertSame('draft', $cost->status);

        $allocations = $cost->allocations()->orderBy('goods_receipt_item_id')->get();
        $this->assertSame('10.0000', $allocations[0]->allocated_amount);
        $this->assertSame('30.0000', $allocations[1]->allocated_amount);

        app(LandedCostService::class)->post($cost, $user->id);

        $this->assertSame('posted', $cost->fresh()->status);
        $this->assertSame('11.0000', $movements[0]->fresh()->unit_cost);
        $this->assertSame('33.0000', $movements[1]->fresh()->unit_cost);

        $posting = AccountingPosting::query()
            ->where('source_type', LandedCost::class)
            ->where('source_id', $cost->id)
            ->where('event_key', 'posted')
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertSame(
            '40.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-INVENTORY')->debit,
        );
        $this->assertSame(
            '40.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-LANDED-COST-CLEARING')->credit,
        );

        $this->assertSame(
            '20.0000',
            \App\Support\Decimal::normalize((string) StockMovement::query()
                ->where('reference_type', GoodsReceipt::class)
                ->where('reference_id', $receipt->id)
                ->sum('quantity')),
        );
    }

    public function test_quantity_allocation_uses_received_quantity_not_line_value(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$receipt] = $this->receipt([
            ['quantity' => '1.0000', 'unit_cost' => '100.0000'],
            ['quantity' => '3.0000', 'unit_cost' => '10.0000'],
        ]);

        $cost = app(LandedCostService::class)->create($receipt, [
            'cost_date' => '2026-09-30',
            'allocation_method' => 'quantity',
            'charges' => [
                ['category' => 'transport', 'amount' => '40.0000'],
            ],
        ], $user->id);

        $allocations = $cost->allocations()->orderBy('goods_receipt_item_id')->get();

        $this->assertSame('10.0000', $allocations[0]->allocated_amount);
        $this->assertSame('30.0000', $allocations[1]->allocated_amount);
        $this->assertSame('10.0000', $allocations[0]->unit_cost_increment);
        $this->assertSame('10.0000', $allocations[1]->unit_cost_increment);
    }

    public function test_rounding_remainder_makes_allocations_reconcile_exactly(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$receipt] = $this->receipt([
            ['quantity' => '1.0000', 'unit_cost' => '1.0000'],
            ['quantity' => '1.0000', 'unit_cost' => '1.0000'],
            ['quantity' => '1.0000', 'unit_cost' => '1.0000'],
        ]);

        $cost = app(LandedCostService::class)->create($receipt, [
            'cost_date' => '2026-09-30',
            'allocation_method' => 'quantity',
            'charges' => [
                ['category' => 'handling', 'amount' => '1.0000'],
            ],
        ], $user->id);

        $amounts = $cost->allocations()->orderBy('goods_receipt_item_id')->pluck('allocated_amount')->all();

        $this->assertSame(['0.3333', '0.3333', '0.3334'], $amounts);
        $this->assertSame('1.0000', (string) $cost->allocations()->sum('allocated_amount'));
    }

    public function test_manual_allocation_must_equal_charge_total(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$receipt] = $this->receipt([
            ['quantity' => '2.0000', 'unit_cost' => '10.0000'],
            ['quantity' => '3.0000', 'unit_cost' => '20.0000'],
        ]);

        $items = $receipt->items()->orderBy('id')->get();

        $this->post(route('purchasing.landed-costs.store'), [
            'goods_receipt_id' => $receipt->id,
            'cost_date' => '2026-09-30',
            'allocation_method' => 'manual',
            'charges' => [
                ['category' => 'other', 'amount' => '10.0000'],
            ],
            'manual_allocations' => [
                ['goods_receipt_item_id' => $items[0]->id, 'amount' => '3.0000'],
                ['goods_receipt_item_id' => $items[1]->id, 'amount' => '6.0000'],
            ],
        ])->assertSessionHasErrors('manual_allocations');

        $this->assertSame(0, LandedCost::count());

        $this->post(route('purchasing.landed-costs.store'), [
            'goods_receipt_id' => $receipt->id,
            'cost_date' => '2026-09-30',
            'allocation_method' => 'manual',
            'charges' => [
                ['category' => 'other', 'amount' => '10.0000'],
            ],
            'manual_allocations' => [
                ['goods_receipt_item_id' => $items[0]->id, 'amount' => '3.0000'],
                ['goods_receipt_item_id' => $items[1]->id, 'amount' => '7.0000'],
            ],
        ])->assertRedirect(route('purchasing.landed-costs.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['3.0000', '7.0000'],
            LandedCostAllocation::query()->orderBy('goods_receipt_item_id')->pluck('allocated_amount')->all(),
        );
    }

    public function test_multiple_landed_costs_accumulate_and_reversal_restores_active_valuation(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$receipt, $movements] = $this->receipt([
            ['quantity' => '10.0000', 'unit_cost' => '10.0000'],
        ]);

        $service = app(LandedCostService::class);

        $first = $service->create($receipt, [
            'cost_date' => '2026-09-30',
            'allocation_method' => 'value',
            'charges' => [['category' => 'freight', 'amount' => '10.0000']],
        ], $user->id);
        $service->post($first, $user->id);

        $second = $service->create($receipt, [
            'cost_date' => '2026-10-01',
            'allocation_method' => 'value',
            'charges' => [['category' => 'insurance', 'amount' => '5.0000']],
        ], $user->id);
        $service->post($second, $user->id);

        $this->assertSame('11.5000', $movements[0]->fresh()->unit_cost);

        $service->reverse($second, $user->id, 'Insurance charge cancelled');

        $this->assertSame('11.0000', $movements[0]->fresh()->unit_cost);
        $this->assertSame('posted', $first->fresh()->status);
        $this->assertSame('reversed', $second->fresh()->status);

        $posting = AccountingPosting::query()
            ->where('source_type', LandedCost::class)
            ->where('source_id', $second->id)
            ->where('event_key', 'posted')
            ->firstOrFail();

        $this->assertNotNull($posting->reversed_at);
        $this->assertNotNull($posting->reversal_journal_entry_id);
    }

    public function test_posting_is_blocked_after_affected_stock_has_moved_out(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$receipt, $movements] = $this->receipt([
            ['quantity' => '5.0000', 'unit_cost' => '10.0000'],
        ]);

        $cost = app(LandedCostService::class)->create($receipt, [
            'cost_date' => '2026-09-30',
            'allocation_method' => 'value',
            'charges' => [['category' => 'freight', 'amount' => '5.0000']],
        ], $user->id);

        StockMovement::create([
            'warehouse_id' => $receipt->warehouse_id,
            'product_id' => $receipt->items[0]->product_id,
            'product_variant_id' => $receipt->items[0]->product_variant_id,
            'type' => 'sale',
            'quantity' => '-1.0000',
            'unit_cost' => $movements[0]->unit_cost,
            'occurred_at' => now(),
        ]);

        $this->post(route('purchasing.landed-costs.post', $cost))
            ->assertSessionHasErrors('landed_cost');

        $this->assertSame('draft', $cost->fresh()->status);
        $this->assertSame('10.0000', $movements[0]->fresh()->unit_cost);
        $this->assertSame(
            0,
            AccountingPosting::query()
                ->where('source_type', LandedCost::class)
                ->where('source_id', $cost->id)
                ->count(),
        );
    }

    public function test_cross_business_cannot_create_or_post_landed_cost(): void
    {
        [$userA, $businessA] = $this->context('Owner A', 'Business A');
        $this->actIn($userA, $businessA);
        [$receipt] = $this->receipt([
            ['quantity' => '1.0000', 'unit_cost' => '10.0000'],
        ]);

        $cost = app(LandedCostService::class)->create($receipt, [
            'cost_date' => '2026-09-30',
            'allocation_method' => 'value',
            'charges' => [['category' => 'freight', 'amount' => '1.0000']],
        ], $userA->id);

        [$userB, $businessB] = $this->context('Owner B', 'Business B');
        $this->actIn($userB, $businessB);

        $this->post(route('purchasing.landed-costs.store'), [
            'goods_receipt_id' => $receipt->id,
            'cost_date' => '2026-09-30',
            'allocation_method' => 'value',
            'charges' => [['category' => 'freight', 'amount' => '1.0000']],
        ])->assertSessionHasErrors('goods_receipt_id');

        $this->post(route('purchasing.landed-costs.post', $cost))->assertNotFound();
    }

    private function receipt(array $lines): array
    {
        $supplier = Supplier::create([
            'code' => 'SUP-'.Str::upper(Str::random(5)),
            'name' => 'Landed Supplier',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create([
            'code' => 'WH-'.Str::upper(Str::random(4)),
            'name' => 'Landed Warehouse',
            'is_active' => true,
        ]);
        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'number' => 'PO-'.Str::upper(Str::random(5)),
            'status' => 'ordered',
            'ap_recognition' => 'invoice',
            'order_date' => '2026-09-28',
            'subtotal' => '0.0000',
            'total' => '0.0000',
        ]);

        $total = '0.0000';

        foreach ($lines as $index => $line) {
            $product = Product::create([
                'type' => 'product',
                'name' => 'Landed Item '.($index + 1),
                'sku' => 'LND-'.($index + 1).'-'.Str::upper(Str::random(4)),
                'sale_price' => '0',
            ]);

            $lineTotal = AppSupportDecimal::round(
                AppSupportDecimal::mul($line['quantity'], $line['unit_cost']),
            );
            $total = AppSupportDecimal::add($total, $lineTotal);

            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => $line['quantity'],
                'unit_cost' => $line['unit_cost'],
                'line_total' => $lineTotal,
            ]);
        }

        $order->update(['subtotal' => $total, 'total' => $total]);

        $this->post(route('purchasing.orders.receive', $order), [
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-29',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $receipt = GoodsReceipt::with(['items.stockMovement'])->firstOrFail();

        return [
            $receipt,
            $receipt->items->sortBy('id')->map(fn ($item) => $item->stockMovement)->values(),
        ];
    }

    private function context(string $userName = 'Owner', string $businessName = 'Landed Cost Co'): array
    {
        $user = User::create([
            'name' => $userName,
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('password'),
        ]);
        $business = Business::create(['name' => $businessName]);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (['dashboard', 'settings', 'products', 'inventory', 'purchasing', 'accounting'] as $module) {
            BusinessModule::updateOrCreate(
                ['business_id' => $business->id, 'module_key' => $module],
                ['enabled' => true],
            );
        }

        return [$user, $business];
    }

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();
    }
}
