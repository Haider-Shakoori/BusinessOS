<?php

namespace Tests\Feature;

use App\Models\AccountingPosting;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceAdjustment;
use App\Models\SupplierPaymentAllocation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AgingReportService;
use App\Services\SupplierInvoiceService;
use App\Services\SupplierLedgerService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierInvoiceSettlementTest extends TestCase
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

    public function test_settlement_schema_and_approved_invoice_start_unpaid(): void
    {
        $this->assertTrue(Schema::hasTable('supplier_payment_allocations'));
        $this->assertTrue(Schema::hasTable('supplier_invoice_adjustments'));
        $this->assertTrue(Schema::hasColumn('supplier_invoices', 'amount_due'));
        $this->assertTrue(Schema::hasColumn('supplier_invoices', 'settlement_status'));

        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        $supplier = $this->supplier();
        $invoice = $this->approvedInvoice($user, $supplier, 'A');

        $this->assertSame('0.0000', $invoice->amount_paid);
        $this->assertSame('0.0000', $invoice->credit_total);
        $this->assertSame('0.0000', $invoice->debit_total);
        $this->assertSame('20.0000', $invoice->amount_due);
        $this->assertSame('unpaid', $invoice->settlement_status);
    }

    public function test_direct_partial_payment_allocates_to_invoice_and_updates_aging(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        $supplier = $this->supplier();
        $invoice = $this->approvedInvoice($user, $supplier, 'P');

        $this->post(route('purchasing.supplier-invoices.pay', $invoice), [
            'amount' => '7.0000',
            'payment_method' => 'bank_transfer',
            'payment_date' => '2026-10-01',
            'reference' => 'BANK-7',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $payment = Payment::where('party_type', 'supplier')->firstOrFail();
        $allocation = SupplierPaymentAllocation::firstOrFail();
        $invoice->refresh();

        $this->assertSame($invoice->id, $allocation->supplier_invoice_id);
        $this->assertSame($payment->id, $allocation->payment_id);
        $this->assertSame('7.0000', $allocation->amount);
        $this->assertSame('7.0000', $invoice->amount_paid);
        $this->assertSame('13.0000', $invoice->amount_due);
        $this->assertSame('partially_paid', $invoice->settlement_status);
        $this->assertSame('13.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));

        $aging = app(AgingReportService::class)->payables('2026-10-02');
        $this->assertSame('13.0000', $aging['totals']['total']);

        $posting = AccountingPosting::query()
            ->where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->where('event_key', 'supplier-payment')
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertSame(
            '7.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-AP')->debit,
        );
    }

    public function test_supplier_wide_payment_auto_allocates_oldest_open_invoices_first(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        $supplier = $this->supplier();

        $first = $this->approvedInvoice($user, $supplier, 'OLD', '2026-09-30', '2026-10-05');
        $second = $this->approvedInvoice($user, $supplier, 'NEW', '2026-10-01', '2026-10-15');

        $this->post(route('suppliers.payments.store', $supplier), [
            'amount' => '25.0000',
            'payment_method' => 'bank_transfer',
            'payment_date' => '2026-10-02',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $payment = Payment::where('party_type', 'supplier')->firstOrFail();
        $allocations = SupplierPaymentAllocation::where('payment_id', $payment->id)
            ->orderBy('supplier_invoice_id')
            ->get();

        $this->assertCount(2, $allocations);
        $this->assertSame('20.0000', $allocations->firstWhere('supplier_invoice_id', $first->id)->amount);
        $this->assertSame('5.0000', $allocations->firstWhere('supplier_invoice_id', $second->id)->amount);

        $first->refresh();
        $second->refresh();

        $this->assertSame('paid', $first->settlement_status);
        $this->assertSame('0.0000', $first->amount_due);
        $this->assertSame('partially_paid', $second->settlement_status);
        $this->assertSame('15.0000', $second->amount_due);
        $this->assertSame('15.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));
    }

    public function test_reversing_supplier_payment_reopens_allocated_invoice(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        $supplier = $this->supplier();
        $invoice = $this->approvedInvoice($user, $supplier, 'REV');

        $this->post(route('purchasing.supplier-invoices.pay', $invoice), [
            'amount' => '20.0000',
            'payment_method' => 'cash',
            'payment_date' => '2026-10-01',
        ])->assertSessionHasNoErrors();

        $payment = Payment::where('party_type', 'supplier')->firstOrFail();

        $this->post(route('suppliers.payments.reverse', [$supplier, $payment]), [
            'reversal_reason' => 'Payment cancelled',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $invoice->refresh();

        $this->assertSame('0.0000', $invoice->amount_paid);
        $this->assertSame('20.0000', $invoice->amount_due);
        $this->assertSame('unpaid', $invoice->settlement_status);
        $this->assertSame('20.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));

        $posting = AccountingPosting::query()
            ->where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->where('event_key', 'supplier-payment')
            ->firstOrFail();

        $this->assertNotNull($posting->reversed_at);
    }

    public function test_supplier_credit_note_reduces_ap_and_reversal_restores_it(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        $supplier = $this->supplier();
        $invoice = $this->approvedInvoice($user, $supplier, 'CR');

        $this->post(route('purchasing.supplier-invoices.adjustments.store', $invoice), [
            'type' => 'credit',
            'amount' => '5.0000',
            'note_date' => '2026-10-01',
            'reason' => 'Supplier rebate',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $adjustment = SupplierInvoiceAdjustment::firstOrFail();
        $invoice->refresh();

        $this->assertSame('SCN-000001', $adjustment->number);
        $this->assertSame('5.0000', $invoice->credit_total);
        $this->assertSame('15.0000', $invoice->amount_due);
        $this->assertSame('partially_paid', $invoice->settlement_status);
        $this->assertSame('15.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));

        $posting = AccountingPosting::query()
            ->where('source_type', SupplierInvoiceAdjustment::class)
            ->where('source_id', $adjustment->id)
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertSame(
            '5.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-AP')->debit,
        );
        $this->assertSame(
            '5.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-PURCHASE-CREDITS')->credit,
        );

        $this->post(route('purchasing.supplier-invoices.adjustments.reverse', [$invoice, $adjustment]), [
            'reversal_reason' => 'Credit note cancelled',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $invoice->refresh();

        $this->assertSame('0.0000', $invoice->credit_total);
        $this->assertSame('20.0000', $invoice->amount_due);
        $this->assertSame('unpaid', $invoice->settlement_status);
        $this->assertSame('20.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));
        $this->assertNotNull($posting->fresh()->reversed_at);
    }

    public function test_supplier_debit_note_increases_ap_and_can_be_fully_paid(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        $supplier = $this->supplier();
        $invoice = $this->approvedInvoice($user, $supplier, 'DR');

        $this->post(route('purchasing.supplier-invoices.adjustments.store', $invoice), [
            'type' => 'debit',
            'amount' => '4.0000',
            'note_date' => '2026-10-01',
            'reason' => 'Approved freight charge',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $adjustment = SupplierInvoiceAdjustment::firstOrFail();
        $invoice->refresh();

        $this->assertSame('SDN-000001', $adjustment->number);
        $this->assertSame('4.0000', $invoice->debit_total);
        $this->assertSame('24.0000', $invoice->amount_due);
        $this->assertSame('24.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));

        $posting = AccountingPosting::query()
            ->where('source_type', SupplierInvoiceAdjustment::class)
            ->where('source_id', $adjustment->id)
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertSame(
            '4.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-PURCHASE-ADJ-EXP')->debit,
        );
        $this->assertSame(
            '4.0000',
            $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-AP')->credit,
        );

        $this->post(route('purchasing.supplier-invoices.pay', $invoice), [
            'amount' => '24.0000',
            'payment_method' => 'bank_transfer',
            'payment_date' => '2026-10-02',
        ])->assertSessionHasNoErrors();

        $invoice->refresh();

        $this->assertSame('24.0000', $invoice->amount_paid);
        $this->assertSame('0.0000', $invoice->amount_due);
        $this->assertSame('paid', $invoice->settlement_status);
        $this->assertSame('0.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));
    }

    public function test_credit_note_cannot_exceed_invoice_outstanding_balance(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        $supplier = $this->supplier();
        $invoice = $this->approvedInvoice($user, $supplier, 'LIMIT');

        $this->post(route('purchasing.supplier-invoices.adjustments.store', $invoice), [
            'type' => 'credit',
            'amount' => '20.0001',
            'note_date' => '2026-10-01',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, SupplierInvoiceAdjustment::count());
        $this->assertSame('20.0000', $invoice->fresh()->amount_due);
    }

    public function test_cross_business_cannot_pay_or_adjust_supplier_invoice(): void
    {
        [$userA, $businessA] = $this->context('Owner A', 'Business A');
        $this->actIn($userA, $businessA);
        $supplier = $this->supplier();
        $invoice = $this->approvedInvoice($userA, $supplier, 'TENANT');

        [$userB, $businessB] = $this->context('Owner B', 'Business B');
        $this->actIn($userB, $businessB);

        $this->post(route('purchasing.supplier-invoices.pay', $invoice), [
            'amount' => '1.0000',
            'payment_method' => 'cash',
            'payment_date' => '2026-10-01',
        ])->assertNotFound();

        $this->post(route('purchasing.supplier-invoices.adjustments.store', $invoice), [
            'type' => 'credit',
            'amount' => '1.0000',
            'note_date' => '2026-10-01',
        ])->assertNotFound();
    }

    private function approvedInvoice(
        User $user,
        Supplier $supplier,
        string $suffix,
        string $invoiceDate = '2026-09-30',
        string $dueDate = '2026-10-15',
    ): SupplierInvoice {
        $product = Product::create([
            'type' => 'product',
            'name' => 'Material '.$suffix,
            'sku' => 'MAT-'.$suffix.'-'.Str::upper(Str::random(4)),
            'sale_price' => '0',
        ]);
        $warehouse = Warehouse::create([
            'code' => 'WH-'.$suffix.'-'.Str::upper(Str::random(3)),
            'name' => 'Warehouse '.$suffix,
            'is_active' => true,
        ]);
        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'number' => 'PO-'.$suffix.'-'.Str::upper(Str::random(4)),
            'status' => 'ordered',
            'ap_recognition' => 'invoice',
            'order_date' => '2026-09-28',
            'expected_date' => $dueDate,
            'subtotal' => '20.0000',
            'total' => '20.0000',
        ]);
        $item = $order->items()->create([
            'product_id' => $product->id,
            'quantity' => '2.0000',
            'unit_cost' => '10.0000',
            'line_total' => '20.0000',
        ]);

        $this->post(route('purchasing.orders.receive', $order), [
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-29',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $service = app(SupplierInvoiceService::class);
        $invoice = $service->create($order->fresh()->load('items'), [
            'supplier_invoice_number' => 'BILL-'.$suffix,
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'items' => [[
                'purchase_order_item_id' => $item->id,
                'quantity' => '2.0000',
                'unit_cost' => '10.0000',
            ]],
        ], $user->id);

        $service->submit($invoice, $user->id);

        return $service->approve($invoice->fresh(), $user->id);
    }

    private function supplier(): Supplier
    {
        return Supplier::create([
            'code' => 'SUP-'.Str::upper(Str::random(5)),
            'name' => 'Settlement Supplier',
            'opening_balance' => '0.0000',
            'is_active' => true,
        ]);
    }

    private function context(string $userName = 'Owner', string $businessName = 'Settlement Co'): array
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
