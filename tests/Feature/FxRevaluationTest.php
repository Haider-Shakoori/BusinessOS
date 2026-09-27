<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessCurrency;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\FxRevaluation;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\FxRevaluationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class FxRevaluationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_open_foreign_receivable_is_revalued_and_reversed_next_day_once(): void
    {
        $user = User::create(['name' => 'FX Revaluation Owner', 'email' => Str::random(12).'@example.test', 'password' => Hash::make('password')]);
        $business = Business::create(['name' => 'FX Revaluation Co']);
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
        ExchangeRate::create(['currency_code' => 'USD', 'rate' => '75', 'effective_date' => '2026-09-30']);

        $customer = Customer::create(['name' => 'Open USD Customer']);
        $invoice = new Invoice;
        $invoice->forceFill([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-FXR-1',
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

        $service = app(FxRevaluationService::class);
        $this->assertSame(1, $service->revalueOpenReceivables('2026-09-30'));
        $this->assertSame(0, $service->revalueOpenReceivables('2026-09-30'));

        $revaluation = FxRevaluation::with(['journalEntry.lines.account', 'reversalJournalEntry.lines.account'])->firstOrFail();
        $this->assertSame('500.0000', (string) $revaluation->adjustment);
        $this->assertSame('2026-10-01', $revaluation->reversalJournalEntry->entry_date->toDateString());

        $entryLines = $revaluation->journalEntry->lines;
        $this->assertSame('500.0000', $entryLines->firstWhere('account.code', 'AUTO-AR')->debit);
        $this->assertSame('500.0000', $entryLines->firstWhere('account.code', 'AUTO-FX-UNREALIZED-GAIN')->credit);

        $reversalLines = $revaluation->reversalJournalEntry->lines;
        $this->assertSame('500.0000', $reversalLines->firstWhere('account.code', 'AUTO-AR')->credit);
        $this->assertSame('500.0000', $reversalLines->firstWhere('account.code', 'AUTO-FX-UNREALIZED-GAIN')->debit);
    }
}
