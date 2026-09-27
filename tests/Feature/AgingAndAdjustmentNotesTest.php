<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountAdjustmentNoteService;
use App\Services\AgingReportService;
use App\Services\SupplierLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgingAndAdjustmentNotesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_credit_and_debit_notes_adjust_operational_balances_and_aging(): void
    {
        $user = User::create(['name' => 'Finance Owner', 'email' => Str::random(12).'@example.test', 'password' => Hash::make('password')]);
        $business = Business::create(['name' => 'Aging Co']);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();

        $customer = Customer::create(['name' => 'Aging Customer']);
        $invoice = new Invoice;
        $invoice->forceFill([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-AGING-1',
            'date' => '2026-08-01',
            'due_date' => '2026-08-15',
            'status' => 'sent',
            'subtotal' => '1000',
            'total' => '1000',
            'amount_paid' => '0',
            'amount_due' => '1000',
            'currency_code' => 'AFN',
            'exchange_rate' => '1',
            'base_amount' => '1000',
            'created_by' => $user->id,
        ])->save();

        $supplier = Supplier::create(['code' => 'SUP-AGING', 'name' => 'Aging Supplier', 'is_active' => true]);
        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'number' => 'PO-AGING-1',
            'status' => 'received',
            'order_date' => '2026-08-01',
            'expected_date' => '2026-08-20',
            'subtotal' => '800',
            'total' => '800',
        ]);

        $notes = app(AccountAdjustmentNoteService::class);
        $credit = $notes->customerCredit($invoice, '250', '2026-09-01', 'Price allowance', $user->id);
        $debit = $notes->supplierDebit($order, '100', '2026-09-01', 'Supplier allowance', $user->id);

        $this->assertSame('CN-', substr($credit->number, 0, 3));
        $this->assertSame('DN-', substr($debit->number, 0, 3));
        $this->assertSame('750.0000', (string) $invoice->fresh()->amount_due);
        $this->assertSame('700.0000', app(SupplierLedgerService::class)->outstandingBalance($supplier));

        $aging = app(AgingReportService::class);
        $ar = $aging->receivables('2026-09-15');
        $ap = $aging->payables('2026-09-15');

        $this->assertSame('750.0000', $ar['totals']['days_31_60']);
        $this->assertSame('700.0000', $ap['totals']['days_1_30']);

        $credit->journalEntry->load('lines.account');
        $this->assertSame('250.0000', $credit->journalEntry->lines->firstWhere('account.code', 'AUTO-AR')->credit);
        $debit->journalEntry->load('lines.account');
        $this->assertSame('100.0000', $debit->journalEntry->lines->firstWhere('account.code', 'AUTO-AP')->debit);
    }
}
