<?php

namespace Tests\Feature;

use App\Models\AccountingPosting;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\GoodsReceipt;
use App\Models\InventoryReturn;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AgingReportService;
use App\Services\PaymentService;
use App\Services\SupplierInvoiceService;
use App\Services\SupplierLedgerService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierInvoiceAccountingTest extends TestCase
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

    public function test_ap_recognition_cutover_column_defaults_legacy_rows_to_receipt(): void
    {
        $this->assertTrue(Schema::hasColumn('purchase_orders', 'ap_recognition'));

        [$user, $business] = $this->context();
        $this->actIn($user, $business);

        $supplier = $this->supplier();
        $product = $this->product();

        $legacy = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'number' => 'LEGACY-PO',
            'status' => 'received',
            'order_date' => '2026-09-20',
            'subtotal' => '10.0000',
            'total' => '10.0000',
        ]);
        $legacy->items()->create([
            'product_id' => $product->id,
            'quantity' => '1.0000',
            'unit_cost' => '10.0000',
            'line_total' => '10.0000',
        ]);

        $this->assertSame('receipt', $legacy->fresh()->ap_recognition);
    }

    public function test_new_direct_purchase_order_uses_invoice_recognition_and_exact_decimal_total(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);

        $supplier = $this->supplier();
        $product = $this->product();

        $this->post(route('purchasing.orders.store'), [
            'supplier_id' => $supplier->id,
            'number' => 'PO-DIRECT-1',
            'order_date' => '2026-09-28',
            'product_id' => $product->id,
            'quantity' => '2.5000',
            'unit_cost' => '100.1250',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = PurchaseOrder::firstOrFail();

        $this->assertSame('invoice', $order->ap_recognition);
        $this->assertSame('250.3125', $order->total);
    }

    public function test_invoice_recognition_grn_posts_inventory_to_grni_without_supplier_payable(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$order, $supplier, $warehouse] = $this->receivedInvoiceModeOrder();

        $receipt = GoodsReceipt::query()->where('purchase_order_id', $order->id)->firstOrFail();
        $posting = AccountingPosting::query()
            ->where('source_type', GoodsReceipt::class)
            ->where('source_id', $receipt->id)
            ->where('event_key', 'posted')
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertSame(
            '20.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-INVENTORY')->debit,
        );
        $this->assertSame(
            '20.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-GRNI')->credit,
        );
        $this->assertNull($posting->journalEntry->lines->firstWhere('account.code', 'AUTO-AP'));

        $this->assertSame('0.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));
        $this->assertSame('0.0000', app(AgingReportService::class)->payables('2026-09-30')['totals']['total']);
        $this->assertSame('received', $order->fresh()->status);
        $this->assertSame($warehouse->id, $receipt->warehouse_id);
    }

    public function test_approved_matched_supplier_invoice_clears_grni_to_accounts_payable(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$order, $supplier] = $this->receivedInvoiceModeOrder();

        $invoice = $this->createSubmitApproveInvoice($order, $user, '20.0000', '10.0000');

        $posting = AccountingPosting::query()
            ->where('source_type', SupplierInvoice::class)
            ->where('source_id', $invoice->id)
            ->where('event_key', 'approved')
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertSame(
            '20.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-GRNI')->debit,
        );
        $this->assertSame(
            '20.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-AP')->credit,
        );
        $this->assertNull($posting->journalEntry->lines->firstWhere('account.code', 'AUTO-PPV'));

        $this->assertSame('20.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));

        $aging = app(AgingReportService::class)->payables('2026-10-01');
        $this->assertSame('20.0000', $aging['totals']['total']);
        $this->assertSame($invoice->number, $aging['rows']->first()['document']);
    }

    public function test_price_variance_posts_separately_before_accounts_payable(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$order, $supplier] = $this->receivedInvoiceModeOrder();

        $service = app(SupplierInvoiceService::class);
        $invoice = $service->create($order, [
            'supplier_invoice_number' => 'VAR-001',
            'invoice_date' => '2026-09-30',
            'items' => [[
                'purchase_order_item_id' => $order->items[0]->id,
                'quantity' => '2.0000',
                'unit_cost' => '12.0000',
            ]],
        ], $user->id);

        $service->submit($invoice, $user->id);
        $service->approve(
            $invoice->fresh(),
            $user->id,
            'Approved supplier price increase.',
        );

        $posting = AccountingPosting::query()
            ->where('source_type', SupplierInvoice::class)
            ->where('source_id', $invoice->id)
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertSame(
            '20.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-GRNI')->debit,
        );
        $this->assertSame(
            '4.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-PPV')->debit,
        );
        $this->assertSame(
            '24.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-AP')->credit,
        );
        $this->assertSame('24.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));
    }

    public function test_preinvoice_purchase_return_reverses_grni_and_reduces_matchable_quantity(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$order, $supplier, $warehouse] = $this->receivedInvoiceModeOrder();

        $item = $order->items[0];

        $this->post(route('inventory.returns.purchases.store'), [
            'purchase_order_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '1.0000',
            'reason' => 'Damaged before supplier invoice',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $return = InventoryReturn::firstOrFail();
        $posting = AccountingPosting::query()
            ->where('source_type', InventoryReturn::class)
            ->where('source_id', $return->id)
            ->where('event_key', 'purchase-return')
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertSame(
            '10.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-GRNI')->debit,
        );
        $this->assertSame(
            '10.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-INVENTORY')->credit,
        );
        $this->assertNull($posting->journalEntry->lines->firstWhere('account.code', 'AUTO-AP'));

        $available = app(SupplierInvoiceService::class)->availableQuantities($order->fresh()->load('items'));
        $this->assertSame('1.0000', $available->get($item->id));
        $this->assertSame('0.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));
    }

    public function test_submitted_supplier_invoice_reservation_blocks_returning_reserved_quantity(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$order, $supplier, $warehouse] = $this->receivedInvoiceModeOrder();

        $service = app(SupplierInvoiceService::class);
        $invoice = $service->create($order, [
            'supplier_invoice_number' => 'RES-001',
            'invoice_date' => '2026-09-30',
            'items' => [[
                'purchase_order_item_id' => $order->items[0]->id,
                'quantity' => '1.0000',
                'unit_cost' => '10.0000',
            ]],
        ], $user->id);
        $service->submit($invoice, $user->id);

        $this->post(route('inventory.returns.purchases.store'), [
            'purchase_order_item_id' => $order->items[0]->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '2.0000',
            'reason' => 'Attempt to return reserved stock',
        ])->assertSessionHasErrors('return');

        $this->assertSame(0, InventoryReturn::count());
        $this->assertSame('0.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));
    }

    public function test_supplier_payment_is_limited_to_approved_invoice_payable(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$order, $supplier] = $this->receivedInvoiceModeOrder();

        $this->createSubmitApproveInvoice($order, $user, '20.0000', '10.0000');

        $this->post(route('suppliers.payments.store', $supplier), [
            'amount' => '21.0000',
            'payment_method' => 'bank_transfer',
            'payment_date' => '2026-10-01',
        ])->assertSessionHasErrors('amount');

        $this->post(route('suppliers.payments.store', $supplier), [
            'amount' => '20.0000',
            'payment_method' => 'bank_transfer',
            'payment_date' => '2026-10-01',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('0.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));
        $this->assertSame('0.0000', app(AgingReportService::class)->payables('2026-10-02')['totals']['total']);
    }

    public function test_legacy_receipt_recognition_still_posts_directly_to_accounts_payable(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);

        $supplier = $this->supplier();
        $product = $this->product();
        $warehouse = Warehouse::create([
            'code' => 'LEG-WH',
            'name' => 'Legacy Warehouse',
            'is_active' => true,
        ]);

        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'number' => 'LEGACY-PO-AP',
            'status' => 'ordered',
            'ap_recognition' => 'receipt',
            'order_date' => '2026-09-20',
            'subtotal' => '10.0000',
            'total' => '10.0000',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => '1.0000',
            'unit_cost' => '10.0000',
            'line_total' => '10.0000',
        ]);

        $this->post(route('purchasing.orders.receive', $order), [
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-21',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $receipt = GoodsReceipt::firstOrFail();
        $posting = AccountingPosting::query()
            ->where('source_type', GoodsReceipt::class)
            ->where('source_id', $receipt->id)
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertSame(
            '10.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-AP')->credit,
        );
        $this->assertNull($posting->journalEntry->lines->firstWhere('account.code', 'AUTO-GRNI'));
        $this->assertSame('10.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));
    }

    private function createSubmitApproveInvoice(
        PurchaseOrder $order,
        User $user,
        string $expectedTotal,
        string $unitCost,
    ): SupplierInvoice {
        $service = app(SupplierInvoiceService::class);
        $invoice = $service->create($order, [
            'supplier_invoice_number' => 'BILL-'.Str::upper(Str::random(5)),
            'invoice_date' => '2026-09-30',
            'due_date' => '2026-10-15',
            'items' => [[
                'purchase_order_item_id' => $order->items[0]->id,
                'quantity' => '2.0000',
                'unit_cost' => $unitCost,
            ]],
        ], $user->id);

        $this->assertSame($expectedTotal, $invoice->total);

        $service->submit($invoice, $user->id);

        return $service->approve($invoice->fresh(), $user->id);
    }

    private function receivedInvoiceModeOrder(): array
    {
        $supplier = $this->supplier();
        $product = $this->product();
        $warehouse = Warehouse::create([
            'code' => 'WH-'.Str::upper(Str::random(4)),
            'name' => 'Main Warehouse',
            'is_active' => true,
        ]);

        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'number' => 'PO-'.Str::upper(Str::random(6)),
            'status' => 'ordered',
            'ap_recognition' => 'invoice',
            'order_date' => '2026-09-28',
            'expected_date' => '2026-10-05',
            'subtotal' => '20.0000',
            'total' => '20.0000',
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => '2.0000',
            'unit_cost' => '10.0000',
            'line_total' => '20.0000',
        ]);

        $this->post(route('purchasing.orders.receive', $order), [
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-29',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return [$order->fresh()->load('items'), $supplier, $warehouse];
    }

    private function supplier(): Supplier
    {
        return Supplier::create([
            'code' => 'SUP-'.Str::upper(Str::random(5)),
            'name' => 'Supplier '.Str::upper(Str::random(4)),
            'opening_balance' => '0.0000',
            'is_active' => true,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'type' => 'product',
            'name' => 'Material '.Str::upper(Str::random(4)),
            'sku' => 'MAT-'.Str::upper(Str::random(6)),
            'sale_price' => '0',
        ]);
    }

    private function context(string $userName = 'Owner', string $businessName = 'GRNI Co'): array
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
