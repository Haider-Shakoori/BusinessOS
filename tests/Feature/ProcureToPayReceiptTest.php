<?php

namespace Tests\Feature;

use App\Models\AccountingPosting;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRfq;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AgingReportService;
use App\Services\SupplierLedgerService;
use App\Support\Decimal;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcureToPayReceiptTest extends TestCase
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

    public function test_goods_receipt_schema_and_quote_to_po_traceability_exist(): void
    {
        $this->assertTrue(Schema::hasTable('goods_receipts'));
        $this->assertTrue(Schema::hasTable('goods_receipt_items'));
        $this->assertTrue(Schema::hasColumn('purchase_orders', 'supplier_quotation_id'));
        $this->assertTrue(Schema::hasColumn('purchase_order_items', 'supplier_quotation_item_id'));
    }

    public function test_selected_quote_converts_once_to_numbered_multiline_purchase_order(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$rfq, $quote] = $this->selectedQuote($user);

        $this->post(route('purchasing.rfqs.quotations.purchase-order', [$rfq, $quote]), [
            'order_date' => '2026-09-28',
            'expected_date' => '2026-10-05',
            'notes' => 'Award converted to PO',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = PurchaseOrder::with('items')->firstOrFail();

        $this->assertSame('PO-000001', $order->number);
        $this->assertSame('ordered', $order->status);
        $this->assertSame('invoice', $order->ap_recognition);
        $this->assertSame($quote->supplier_id, $order->supplier_id);
        $this->assertSame($quote->id, $order->supplier_quotation_id);
        $this->assertSame($rfq->id, $order->purchase_rfq_id);
        $this->assertSame($rfq->purchase_requisition_id, $order->purchase_requisition_id);
        $this->assertSame($user->id, $order->converted_by);
        $this->assertSame('310.0000', $order->total);
        $this->assertCount(2, $order->items);
        $this->assertNotNull($order->items[0]->supplier_quotation_item_id);

        $this->post(route('purchasing.rfqs.quotations.purchase-order', [$rfq, $quote]), [
            'order_date' => '2026-09-28',
        ])->assertSessionHasErrors('quotation');

        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_expired_selected_quote_cannot_be_converted(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$rfq, $quote] = $this->selectedQuote($user, '2026-09-29');

        $this->post(route('purchasing.rfqs.quotations.purchase-order', [$rfq, $quote]), [
            'order_date' => '2026-09-30',
        ])->assertSessionHasErrors('order_date');

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_partial_goods_receipt_updates_stock_payable_and_accounting_by_received_value(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$rfq, $quote, $supplier] = $this->selectedQuote($user);
        $order = $this->convert($rfq, $quote);
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main Warehouse', 'is_active' => true]);

        $firstItem = $order->items->firstOrFail();

        $this->post(route('purchasing.orders.receive', $order), [
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-29',
            'items' => [
                ['purchase_order_item_id' => $firstItem->id, 'quantity' => '1.0000'],
                ['purchase_order_item_id' => $order->items[1]->id, 'quantity' => '0'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $receipt = GoodsReceipt::with('items')->firstOrFail();

        $this->assertSame('GRN-000001', $receipt->number);
        $this->assertSame('100.0000', $receipt->total);
        $this->assertSame('1.0000', $receipt->items->first()->quantity);
        $this->assertSame('partially_received', $order->fresh()->status);

        $movement = StockMovement::firstOrFail();
        $this->assertSame(GoodsReceipt::class, $movement->reference_type);
        $this->assertSame($receipt->id, $movement->reference_id);
        $this->assertSame('1.0000', $movement->quantity);

        $summary = app(SupplierLedgerService::class)->summary($supplier);
        $this->assertSame('0.0000', $summary['total_purchased']);
        $this->assertSame('0.0000', $summary['outstanding_balance']);

        $aging = app(AgingReportService::class)->payables('2026-09-30');
        $this->assertSame('0.0000', $aging['totals']['total']);

        $posting = AccountingPosting::query()
            ->where('source_type', GoodsReceipt::class)
            ->where('source_id', $receipt->id)
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $debit = $posting->journalEntry->lines->reduce(
            fn (string $carry, $line): string => Decimal::add($carry, (string) $line->debit),
            '0.0000',
        );
        $credit = $posting->journalEntry->lines->reduce(
            fn (string $carry, $line): string => Decimal::add($carry, (string) $line->credit),
            '0.0000',
        );

        $this->assertSame('100.0000', $debit);
        $this->assertSame('100.0000', $credit);
        $this->assertSame(
            '100.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-GRNI')->credit,
        );
        $this->assertNull($posting->journalEntry->lines->firstWhere('account.code', 'AUTO-AP'));
    }

    public function test_over_receipt_is_blocked_and_final_receipt_closes_purchase_order(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$rfq, $quote, $supplier] = $this->selectedQuote($user);
        $order = $this->convert($rfq, $quote);
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main Warehouse', 'is_active' => true]);

        $first = $order->items[0];

        $this->post(route('purchasing.orders.receive', $order), [
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-29',
            'items' => [
                ['purchase_order_item_id' => $first->id, 'quantity' => '1.0000'],
                ['purchase_order_item_id' => $order->items[1]->id, 'quantity' => '0'],
            ],
        ])->assertRedirect();

        $this->post(route('purchasing.orders.receive', $order), [
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-30',
            'items' => [
                ['purchase_order_item_id' => $first->id, 'quantity' => '2.0000'],
            ],
        ])->assertSessionHasErrors('items');

        $this->assertSame(1, GoodsReceipt::count());

        $this->post(route('purchasing.orders.receive', $order->fresh()), [
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-30',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, GoodsReceipt::count());
        $this->assertSame('received', $order->fresh()->status);
        $this->assertSame('0.0000', app(SupplierLedgerService::class)->totalPurchased($supplier));
        $this->assertSame('0.0000', app(AgingReportService::class)->payables('2026-09-30')['totals']['total']);
    }

    public function test_cross_business_cannot_convert_quote_or_receive_purchase_order(): void
    {
        [$userA, $businessA] = $this->context('Owner A', 'Business A');
        $this->actIn($userA, $businessA);
        [$rfq, $quote] = $this->selectedQuote($userA);
        $order = $this->convert($rfq, $quote);

        [$userB, $businessB] = $this->context('Owner B', 'Business B');
        $this->actIn($userB, $businessB);
        $warehouseB = Warehouse::create(['code' => 'B-WH', 'name' => 'Business B Warehouse', 'is_active' => true]);

        $this->post(route('purchasing.rfqs.quotations.purchase-order', [$rfq, $quote]), [
            'order_date' => '2026-09-28',
        ])->assertNotFound();

        $this->post(route('purchasing.orders.receive', $order), [
            'warehouse_id' => $warehouseB->id,
            'receipt_date' => '2026-09-29',
        ])->assertNotFound();

        $this->assertSame(0, GoodsReceipt::count());
    }

    private function selectedQuote(User $user, ?string $validUntil = '2026-10-10'): array
    {
        $supplier = Supplier::create([
            'code' => 'SUP-'.Str::upper(Str::random(5)),
            'name' => 'Selected Supplier',
            'is_active' => true,
        ]);

        $paper = Product::create([
            'type' => 'product',
            'name' => 'Paper Roll',
            'sku' => 'PAPER-'.Str::upper(Str::random(5)),
            'sale_price' => '0',
        ]);
        $glue = Product::create([
            'type' => 'product',
            'name' => 'Glue',
            'sku' => 'GLUE-'.Str::upper(Str::random(5)),
            'sale_price' => '0',
        ]);

        $requisition = PurchaseRequisition::create([
            'number' => 'PRQ-TEST-'.Str::upper(Str::random(5)),
            'status' => 'approved',
            'request_date' => '2026-09-27',
            'purpose' => 'Factory materials',
            'requested_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'estimated_total' => '311.8125',
        ]);

        $reqItems = [
            $requisition->items()->create([
                'product_id' => $paper->id,
                'quantity' => '2.5000',
                'estimated_unit_cost' => '100.1250',
                'line_total' => '250.3125',
            ]),
            $requisition->items()->create([
                'product_id' => $glue->id,
                'quantity' => '3.0000',
                'estimated_unit_cost' => '20.5000',
                'line_total' => '61.5000',
            ]),
        ];

        $rfq = PurchaseRfq::create([
            'purchase_requisition_id' => $requisition->id,
            'number' => 'RFQ-TEST-'.Str::upper(Str::random(5)),
            'status' => 'awarded',
            'issue_date' => '2026-09-27',
            'created_by' => $user->id,
            'opened_at' => now(),
            'awarded_at' => now(),
        ]);

        $quote = SupplierQuotation::create([
            'purchase_rfq_id' => $rfq->id,
            'supplier_id' => $supplier->id,
            'number' => 'SQT-TEST-'.Str::upper(Str::random(5)),
            'status' => 'selected',
            'quote_date' => '2026-09-28',
            'valid_until' => $validUntil,
            'subtotal' => '310.0000',
            'total' => '310.0000',
            'recorded_by' => $user->id,
            'selected_by' => $user->id,
            'selected_at' => now(),
        ]);

        $quote->items()->create([
            'purchase_requisition_item_id' => $reqItems[0]->id,
            'product_id' => $paper->id,
            'quantity' => '2.5000',
            'unit_cost' => '100.0000',
            'line_total' => '250.0000',
        ]);
        $quote->items()->create([
            'purchase_requisition_item_id' => $reqItems[1]->id,
            'product_id' => $glue->id,
            'quantity' => '3.0000',
            'unit_cost' => '20.0000',
            'line_total' => '60.0000',
        ]);

        return [$rfq, $quote, $supplier];
    }

    private function convert(PurchaseRfq $rfq, SupplierQuotation $quote): PurchaseOrder
    {
        $this->post(route('purchasing.rfqs.quotations.purchase-order', [$rfq, $quote]), [
            'order_date' => '2026-09-28',
            'expected_date' => '2026-10-05',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return PurchaseOrder::with('items')->firstOrFail();
    }

    private function context(string $userName = 'Owner', string $businessName = 'P2P Co'): array
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
