<?php

namespace Tests\Feature;

use App\Models\AccountingPosting;
use App\Models\Business;
use App\Models\BusinessCurrency;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerLedgerService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ForeignExchangeSettlementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_customer_payment_posts_realized_fx_gain_at_settlement_rate(): void
    {
        $user = User::create(['name' => 'FX Owner', 'email' => Str::random(12).'@example.test', 'password' => Hash::make('password')]);
        $business = Business::create(['name' => 'FX Co']);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();

        Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'symbol' => 'AFN', 'decimals' => 2, 'is_active' => true]);
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'is_active' => true]);
        BusinessCurrency::create(['business_id' => $business->id, 'currency_code' => 'USD']);
        Setting::create(['business_id' => $business->id, 'group' => 'regional', 'key' => 'currency', 'value' => 'AFN']);
        ExchangeRate::create(['currency_code' => 'USD', 'rate' => '70', 'effective_date' => '2026-09-01']);
        ExchangeRate::create(['currency_code' => 'USD', 'rate' => '75', 'effective_date' => '2026-09-20']);

        $customer = Customer::create(['name' => 'USD Customer']);
        $invoice = new Invoice;
        $invoice->forceFill([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-FX-1',
            'date' => '2026-09-01',
            'status' => 'sent',
            'subtotal' => '100',
            'total' => '100',
            'amount_paid' => '0',
            'amount_due' => '100',
            'currency_code' => 'USD',
            'exchange_rate' => '70',
            'base_amount' => '7000',
            'created_by' => $user->id,
        ])->save();

        $payment = app(PaymentService::class)->record([
            'invoice_id' => $invoice->id,
            'amount' => '100',
            'payment_date' => '2026-09-20',
            'payment_method' => 'cash',
        ], $user->id);

        $this->assertSame('75.00000000', (string) $payment->exchange_rate);
        $this->assertSame('7500.0000', (string) $payment->base_amount);

        $posting = AccountingPosting::query()
            ->where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $lines = $posting->journalEntry->lines;
        $this->assertSame('7500.0000', $lines->firstWhere('account.code', 'AUTO-CASH')->debit);
        $this->assertSame('7000.0000', $lines->firstWhere('account.code', 'AUTO-AR')->credit);
        $this->assertSame('500.0000', $lines->firstWhere('account.code', 'AUTO-FX-GAIN')->credit);
        $this->assertSame('0.0000', app(CustomerLedgerService::class)->outstandingBalance($customer));
    }

    public function test_customer_payment_posts_realized_fx_loss_when_settlement_rate_falls(): void
    {
        $user = User::create(['name' => 'FX Loss Owner', 'email' => Str::random(12).'@example.test', 'password' => Hash::make('password')]);
        $business = Business::create(['name' => 'FX Loss Co']);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();

        Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'symbol' => 'AFN', 'decimals' => 2, 'is_active' => true]);
        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'is_active' => true]);
        BusinessCurrency::create(['business_id' => $business->id, 'currency_code' => 'USD']);
        Setting::create(['business_id' => $business->id, 'group' => 'regional', 'key' => 'currency', 'value' => 'AFN']);
        ExchangeRate::create(['currency_code' => 'USD', 'rate' => '70', 'effective_date' => '2026-09-01']);
        ExchangeRate::create(['currency_code' => 'USD', 'rate' => '65', 'effective_date' => '2026-09-20']);

        $customer = Customer::create(['name' => 'USD Loss Customer']);
        $invoice = new Invoice;
        $invoice->forceFill([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-FX-LOSS',
            'date' => '2026-09-01',
            'status' => 'sent',
            'subtotal' => '100',
            'total' => '100',
            'amount_paid' => '0',
            'amount_due' => '100',
            'currency_code' => 'USD',
            'exchange_rate' => '70',
            'base_amount' => '7000',
            'created_by' => $user->id,
        ])->save();

        $payment = app(PaymentService::class)->record([
            'invoice_id' => $invoice->id,
            'amount' => '100',
            'payment_date' => '2026-09-20',
            'payment_method' => 'cash',
        ], $user->id);

        $posting = AccountingPosting::query()
            ->where('source_type', Payment::class)
            ->where('source_id', $payment->id)
            ->with('journalEntry.lines.account')
            ->firstOrFail();

        $lines = $posting->journalEntry->lines;
        $this->assertSame('6500.0000', $lines->firstWhere('account.code', 'AUTO-CASH')->debit);
        $this->assertSame('7000.0000', $lines->firstWhere('account.code', 'AUTO-AR')->credit);
        $this->assertSame('500.0000', $lines->firstWhere('account.code', 'AUTO-FX-LOSS')->debit);
    }
}
