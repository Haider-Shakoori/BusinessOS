<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\AccountingBudget;
use App\Models\AccountingPosting;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\CostCenter;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AccountingPostingService;
use App\Services\AccountingReportService;
use App\Services\BudgetVarianceService;
use App\Services\ExpenseService;
use App\Services\FiscalPeriodService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccountingIntegrationReportsTest extends TestCase
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

    private function user(string $name = 'Accounting Owner'): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function business(User $user, string $name = 'Accounting Co'): Business
    {
        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach ([
            'dashboard',
            'settings',
            'customers',
            'sales',
            'products',
            'expenses',
            'inventory',
            'purchasing',
            'accounting',
            'pos',
        ] as $module) {
            BusinessModule::updateOrCreate(
                ['business_id' => $business->id, 'module_key' => $module],
                ['enabled' => true],
            );
        }

        return $business;
    }

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();
    }

    private function customer(): Customer
    {
        return Customer::create([
            'name' => 'Accounting Customer',
            'company_name' => 'Accounting Customer Co',
            'email' => Str::random(8).'@example.test',
        ]);
    }

    private function product(string $name = 'Inventory Item', string $sku = 'INV-ITEM'): Product
    {
        return Product::create([
            'type' => 'product',
            'name' => $name,
            'sku' => $sku,
            'sale_price' => '100.0000',
        ]);
    }

    private function invoicePayload(Customer $customer, string $status = 'sent', string $amount = '100.0000'): array
    {
        return [
            'customer_id' => $customer->id,
            'date' => '2026-09-26',
            'status' => $status,
            'discount_type' => null,
            'discount_amount' => '',
            'notes' => 'Accounting integration test',
            'items' => [[
                'product_id' => null,
                'description' => 'Accounting service',
                'quantity' => '1.0000',
                'unit_price' => $amount,
            ]],
        ];
    }

    private function assertJournalBalanced(JournalEntry $entry): void
    {
        $entry->loadMissing('lines');

        $this->assertEqualsWithDelta(
            (float) $entry->lines->sum('debit'),
            (float) $entry->lines->sum('credit'),
            0.0001,
        );
    }

    public function test_accounting_posting_schema_and_report_route_exist(): void
    {
        $this->assertTrue(Schema::hasTable('accounting_postings'));

        foreach ([
            'business_id',
            'source_type',
            'source_id',
            'event_key',
            'journal_entry_id',
            'reversal_journal_entry_id',
            'reversed_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('accounting_postings', $column));
        }

        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $this->get('/accounting/reports')
            ->assertOk()
            ->assertSee(__('operations.accounting.trial_balance'))
            ->assertSee(__('operations.accounting.profit_loss'))
            ->assertSee(__('operations.accounting.balance_sheet'))
            ->assertSee(__('operations.accounting.general_ledger'));
    }

    public function test_sent_invoice_and_customer_payment_post_once_and_payment_reversal_is_balanced(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);
        $customer = $this->customer();

        $draft = app(InvoiceService::class)->create($this->invoicePayload($customer, 'draft'), $user->id);
        $this->assertSame(0, AccountingPosting::where('source_type', Invoice::class)->where('source_id', $draft->id)->count());

        $invoice = app(InvoiceService::class)->create($this->invoicePayload($customer, 'sent'), $user->id);

        $posting = AccountingPosting::query()
            ->where('source_type', Invoice::class)
            ->where('source_id', $invoice->id)
            ->where('event_key', 'issued')
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertJournalBalanced($posting->journalEntry);
        $this->assertSame('100.0000', $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-AR')->debit);
        $this->assertSame('100.0000', $posting->journalEntry->lines->firstWhere('account.code', 'AUTO-SALES')->credit);

        app(AccountingPostingService::class)->postInvoice($invoice);
        $this->assertSame(1, AccountingPosting::where('source_type', Invoice::class)->where('source_id', $invoice->id)->count());

        $payment = app(PaymentService::class)->record([
            'invoice_id' => $invoice->id,
            'amount' => '40.0000',
            'payment_date' => '2026-09-26',
            'payment_method' => 'cash',
        ], $user->id);

        $paymentPosting = AccountingPosting::query()
            ->where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->where('event_key', 'customer-payment')
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertJournalBalanced($paymentPosting->journalEntry);
        $this->assertSame('40.0000', $paymentPosting->journalEntry->lines->firstWhere('account.code', 'AUTO-CASH')->debit);
        $this->assertSame('40.0000', $paymentPosting->journalEntry->lines->firstWhere('account.code', 'AUTO-AR')->credit);

        app(PaymentService::class)->reverse($payment, 'Payment cancelled', $user->id);

        $paymentPosting->refresh();
        $this->assertNotNull($paymentPosting->reversed_at);
        $this->assertNotNull($paymentPosting->reversal_journal_entry_id);
        $this->assertJournalBalanced($paymentPosting->reversalJournalEntry);

        $this->assertSame(
            (float) $paymentPosting->journalEntry->lines->sum('debit'),
            (float) $paymentPosting->reversalJournalEntry->lines->sum('credit'),
        );
    }

    public function test_expense_update_and_delete_use_reversals_instead_of_mutating_old_journals(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $service = app(ExpenseService::class);

        $expense = $service->store([
            'expense_date' => '2026-09-26',
            'amount' => '20.0000',
            'payment_method' => 'cash',
            'reference' => 'EXP-TEST',
        ], null, $user->id);

        $this->assertSame(1, AccountingPosting::where('source_type', Expense::class)->where('source_id', $expense->id)->count());

        $service->update($expense, [
            'expense_date' => '2026-09-26',
            'amount' => '35.0000',
            'payment_method' => 'cash',
            'reference' => 'EXP-TEST-UPDATED',
        ], null, false, $user->id);

        $postings = AccountingPosting::query()
            ->where('source_type', Expense::class)
            ->where('source_id', $expense->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $postings);
        $this->assertNotNull($postings[0]->reversed_at);
        $this->assertNull($postings[1]->reversed_at);

        $service->destroy($expense->refresh());

        $postings = AccountingPosting::query()
            ->where('source_type', Expense::class)
            ->where('source_id', $expense->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $postings);
        $this->assertNotNull($postings[0]->reversed_at);
        $this->assertNotNull($postings[1]->reversed_at);

        $expenseAccount = Account::where('code', 'AUTO-EXPENSE')->firstOrFail();
        $net = DB::table('journal_lines')
            ->where('account_id', $expenseAccount->id)
            ->selectRaw('COALESCE(SUM(debit),0) debit, COALESCE(SUM(credit),0) credit')
            ->first();

        $this->assertEqualsWithDelta((float) $net->debit, (float) $net->credit, 0.0001);
    }

    public function test_purchase_receipt_return_supplier_payment_and_reversal_flow_through_accounts_payable(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $supplier = Supplier::create([
            'code' => 'SUP-ACC',
            'name' => 'Accounting Supplier',
            'opening_balance' => '0.0000',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main Warehouse', 'is_active' => true]);
        $product = $this->product();

        $this->post('/purchasing/orders', [
            'supplier_id' => $supplier->id,
            'number' => 'PO-ACC-001',
            'order_date' => '2026-09-26',
            'product_id' => $product->id,
            'quantity' => '10.0000',
            'unit_cost' => '5.0000',
        ])->assertRedirect();

        $order = PurchaseOrder::with('items')->firstOrFail();

        $this->post('/purchasing/orders/'.$order->id.'/receive', [
            'warehouse_id' => $warehouse->id,
        ])->assertRedirect();

        $receipt = GoodsReceipt::query()->where('purchase_order_id', $order->id)->firstOrFail();

        $purchasePosting = AccountingPosting::query()
            ->where('source_type', GoodsReceipt::class)
            ->where('source_id', $receipt->id)
            ->where('event_key', 'posted')
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertJournalBalanced($purchasePosting->journalEntry);
        $this->assertSame('50.0000', $purchasePosting->journalEntry->lines->firstWhere('account.code', 'AUTO-INVENTORY')->debit);
        $this->assertSame('50.0000', $purchasePosting->journalEntry->lines->firstWhere('account.code', 'AUTO-AP')->credit);

        $item = $order->items->firstOrFail();

        $this->post('/inventory/returns/purchases', [
            'purchase_order_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '2.0000',
            'reason' => 'Damaged units',
        ])->assertRedirect();

        $return = InventoryReturn::firstOrFail();
        $returnPosting = AccountingPosting::query()
            ->where('source_type', InventoryReturn::class)
            ->where('source_id', $return->id)
            ->where('event_key', 'purchase-return')
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertJournalBalanced($returnPosting->journalEntry);
        $this->assertSame('10.0000', $returnPosting->journalEntry->lines->firstWhere('account.code', 'AUTO-AP')->debit);
        $this->assertSame('10.0000', $returnPosting->journalEntry->lines->firstWhere('account.code', 'AUTO-INVENTORY')->credit);

        $payment = app(PaymentService::class)->recordSupplier($supplier, [
            'amount' => '20.0000',
            'payment_method' => 'bank_transfer',
            'payment_date' => '2026-09-26',
        ], $user->id);

        $supplierPayment = AccountingPosting::query()
            ->where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->where('event_key', 'supplier-payment')
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $this->assertSame('20.0000', $supplierPayment->journalEntry->lines->firstWhere('account.code', 'AUTO-AP')->debit);
        $this->assertSame('20.0000', $supplierPayment->journalEntry->lines->firstWhere('account.code', 'AUTO-BANK')->credit);

        app(PaymentService::class)->reverse($payment, 'Bank rejection', $user->id);
        $supplierPayment->refresh();

        $this->assertNotNull($supplierPayment->reversed_at);
        $this->assertJournalBalanced($supplierPayment->reversalJournalEntry);
    }

    public function test_trial_balance_profit_loss_balance_sheet_and_general_ledger_reconcile(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $ar = Account::create(['code' => 'TEST-AR', 'name' => 'Test Receivable', 'type' => 'asset', 'is_active' => true]);
        $cash = Account::create(['code' => 'TEST-CASH', 'name' => 'Test Cash', 'type' => 'asset', 'is_active' => true]);
        $revenue = Account::create(['code' => 'TEST-REV', 'name' => 'Test Revenue', 'type' => 'income', 'is_active' => true]);
        $expense = Account::create(['code' => 'TEST-EXP', 'name' => 'Test Expense', 'type' => 'expense', 'is_active' => true]);

        $invoice = JournalEntry::create([
            'number' => 'TEST-J-1',
            'entry_date' => '2026-09-10',
            'status' => 'posted',
            'description' => 'Test revenue',
        ]);
        $invoice->lines()->create(['account_id' => $ar->id, 'debit' => '100.0000', 'credit' => '0']);
        $invoice->lines()->create(['account_id' => $revenue->id, 'debit' => '0', 'credit' => '100.0000']);

        $cost = JournalEntry::create([
            'number' => 'TEST-J-2',
            'entry_date' => '2026-09-20',
            'status' => 'posted',
            'description' => 'Test expense',
        ]);
        $cost->lines()->create(['account_id' => $expense->id, 'debit' => '20.0000', 'credit' => '0']);
        $cost->lines()->create(['account_id' => $cash->id, 'debit' => '0', 'credit' => '20.0000']);

        $reports = app(AccountingReportService::class);
        $trial = $reports->trialBalance('2026-09-30');

        $this->assertEqualsWithDelta(
            (float) $trial->sum(fn (array $row) => (float) $row['closing_debit']),
            (float) $trial->sum(fn (array $row) => (float) $row['closing_credit']),
            0.0001,
        );

        $pl = $reports->profitAndLoss('2026-09-01', '2026-09-30');
        $this->assertSame('100.0000', $pl['total_income']);
        $this->assertSame('20.0000', $pl['total_expenses']);
        $this->assertSame('80.0000', $pl['net_profit']);

        $balanceSheet = $reports->balanceSheet('2026-09-30');
        $this->assertSame('80.0000', $balanceSheet['total_assets']);
        $this->assertSame('80.0000', $balanceSheet['current_earnings']);
        $this->assertSame('0.0000', $balanceSheet['difference']);

        $ledger = $reports->generalLedger($ar, '2026-09-01', '2026-09-30');
        $this->assertSame('0.0000', $ledger['opening_balance']);
        $this->assertSame('100.0000', $ledger['closing_balance']);
        $this->assertCount(1, $ledger['lines']);
    }

    public function test_accounting_page_exposes_fiscal_period_management(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $this->get('/accounting')
            ->assertOk()
            ->assertSee(__('operations.accounting.fiscal_periods'))
            ->assertSee(route('accounting.fiscal-periods.store'));

        $this->post('/accounting/fiscal-periods', [
            'name' => 'October 2026',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ])->assertRedirect();

        $period = FiscalPeriod::firstOrFail();
        $this->assertSame('open', $period->status);

        $this->post('/accounting/fiscal-periods/'.$period->id.'/close', [
            'note' => 'Month-end complete',
        ])->assertRedirect();

        $this->assertSame('closed', $period->fresh()->status);

        $this->post('/accounting/fiscal-periods/'.$period->id.'/reopen')
            ->assertRedirect();

        $this->assertSame('open', $period->fresh()->status);
    }

    public function test_closed_fiscal_period_blocks_manual_and_automatic_postings(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $period = app(FiscalPeriodService::class)->create(
            'September 2026',
            '2026-09-01',
            '2026-09-30',
        );
        app(FiscalPeriodService::class)->close($period, $user->id, 'Month-end close');

        $this->assertSame('closed', $period->fresh()->status);

        $debit = Account::create(['code' => 'LOCK-DR', 'name' => 'Lock Debit', 'type' => 'asset', 'is_active' => true]);
        $credit = Account::create(['code' => 'LOCK-CR', 'name' => 'Lock Credit', 'type' => 'equity', 'is_active' => true]);

        $this->post('/accounting/journals', [
            'number' => 'LOCKED-JOURNAL',
            'entry_date' => '2026-09-15',
            'debit_account_id' => $debit->id,
            'credit_account_id' => $credit->id,
            'amount' => '10.0000',
        ])->assertSessionHasErrors('entry_date');

        $this->assertDatabaseMissing('journal_entries', ['number' => 'LOCKED-JOURNAL']);

        $this->expectException(ValidationException::class);

        app(InvoiceService::class)->create(
            $this->invoicePayload($this->customer(), InvoiceStatus::Sent->value, '25.0000'),
            $user->id,
        );
    }

    public function test_fiscal_periods_cannot_overlap_and_reopen_allows_posting_again(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);
        $service = app(FiscalPeriodService::class);

        $period = $service->create('FY 2026', '2026-01-01', '2026-12-31');
        $service->close($period, $user->id);
        $service->reopen($period);

        $this->assertSame('open', $period->fresh()->status);
        $service->assertPostingAllowed('2026-09-15');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot overlap');
        $service->create('Overlapping period', '2026-09-01', '2027-08-31');
    }

    public function test_cost_center_journals_are_tenant_safe_and_report_profitability(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $this->post('/accounting/cost-centers', [
            'code' => 'KBL-SALES',
            'name' => 'Kabul Sales',
            'description' => 'Kabul sales activity',
        ])->assertRedirect();

        $center = CostCenter::firstOrFail();
        $income = Account::create(['code' => 'CC-REV', 'name' => 'CC Revenue', 'type' => 'income', 'is_active' => true]);
        $cash = Account::create(['code' => 'CC-CASH', 'name' => 'CC Cash', 'type' => 'asset', 'is_active' => true]);

        $this->post('/accounting/journals', [
            'number' => 'CC-J-1',
            'entry_date' => '2026-09-27',
            'debit_account_id' => $cash->id,
            'credit_account_id' => $income->id,
            'cost_center_id' => $center->id,
            'amount' => '125.0000',
        ])->assertRedirect();

        $entry = JournalEntry::where('number', 'CC-J-1')->with('lines')->firstOrFail();
        $this->assertTrue($entry->lines->every(fn ($line) => $line->cost_center_id === $center->id));

        $summary = app(AccountingReportService::class)->costCenterSummary('2026-09-01', '2026-09-30')->firstWhere('id', $center->id);
        $this->assertSame('125.0000', $summary['income']);
        $this->assertSame('0.0000', $summary['expenses']);
        $this->assertSame('125.0000', $summary['net_profit']);

        $otherUser = $this->user('Other Owner');
        $otherBusiness = $this->business($otherUser, 'Other Accounting Co');
        $this->actIn($otherUser, $otherBusiness);
        $foreignCenter = CostCenter::create(['code' => 'FOREIGN', 'name' => 'Foreign', 'is_active' => true]);

        $this->actIn($user, $business);
        $this->post('/accounting/journals', [
            'number' => 'CC-FORGED',
            'entry_date' => '2026-09-27',
            'debit_account_id' => $cash->id,
            'credit_account_id' => $income->id,
            'cost_center_id' => $foreignCenter->id,
            'amount' => '10.0000',
        ])->assertSessionHasErrors('cost_center_id');

        $this->assertDatabaseMissing('journal_entries', ['number' => 'CC-FORGED']);
    }

    public function test_budget_variance_uses_posted_actuals_and_cost_center_dimensions(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $center = CostCenter::create(['code' => 'BUD-KBL', 'name' => 'Budget Kabul', 'is_active' => true]);
        $expense = Account::create(['code' => 'BUD-EXP', 'name' => 'Budget Expense', 'type' => 'expense', 'is_active' => true]);
        $cash = Account::create(['code' => 'BUD-CASH', 'name' => 'Budget Cash', 'type' => 'asset', 'is_active' => true]);

        $this->post('/accounting/budgets', [
            'name' => 'October Operating Budget',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'account_id' => $expense->id,
            'cost_center_id' => $center->id,
            'amount' => '1000.0000',
        ])->assertRedirect();

        $budget = AccountingBudget::firstOrFail();

        $this->post('/accounting/journals', [
            'number' => 'BUD-ACT-1',
            'entry_date' => '2026-10-15',
            'debit_account_id' => $expense->id,
            'credit_account_id' => $cash->id,
            'cost_center_id' => $center->id,
            'amount' => '750.0000',
        ])->assertRedirect();

        $row = app(BudgetVarianceService::class)->report($budget)->first();
        $this->assertSame('1000.0000', $row['budget']);
        $this->assertSame('750.0000', $row['actual']);
        $this->assertSame('-250.0000', $row['variance']);
        $this->assertSame(-25.0, $row['variance_percent']);

        $this->get(route('accounting.budgets.report', $budget))
            ->assertOk()
            ->assertSee('October Operating Budget')
            ->assertSee('BUD-EXP');

        $otherUser = $this->user('Budget Other');
        $otherBusiness = $this->business($otherUser, 'Budget Other Co');
        $this->actIn($otherUser, $otherBusiness);
        $foreignCenter = CostCenter::create(['code' => 'OTHER-BUD', 'name' => 'Other Budget', 'is_active' => true]);

        $this->actIn($user, $business);
        $this->post('/accounting/budgets', [
            'name' => 'Forged Budget',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-30',
            'account_id' => $expense->id,
            'cost_center_id' => $foreignCenter->id,
            'amount' => '100.0000',
        ])->assertSessionHasErrors('cost_center_id');

        $this->assertDatabaseMissing('accounting_budgets', ['name' => 'Forged Budget']);
    }

    public function test_historical_backfill_is_idempotent(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $invoice = app(InvoiceService::class)->create(
            $this->invoicePayload($this->customer(), InvoiceStatus::Sent->value, '75.0000'),
            $user->id,
        );

        $posting = AccountingPosting::query()
            ->where('source_type', Invoice::class)
            ->where('source_id', $invoice->id)
            ->firstOrFail();

        $journalId = $posting->journal_entry_id;
        $posting->delete();
        JournalEntry::whereKey($journalId)->delete();

        $this->assertSame(0, AccountingPosting::where('source_type', Invoice::class)->where('source_id', $invoice->id)->count());

        $this->artisan('accounting:backfill', ['--business' => $business->id])
            ->assertExitCode(0);

        $firstPostingCount = AccountingPosting::where('business_id', $business->id)->count();
        $firstJournalCount = JournalEntry::where('business_id', $business->id)->count();

        $this->assertGreaterThan(0, $firstPostingCount);

        $this->artisan('accounting:backfill', ['--business' => $business->id])
            ->assertExitCode(0);

        $this->assertSame($firstPostingCount, AccountingPosting::where('business_id', $business->id)->count());
        $this->assertSame($firstJournalCount, JournalEntry::where('business_id', $business->id)->count());
    }
}
