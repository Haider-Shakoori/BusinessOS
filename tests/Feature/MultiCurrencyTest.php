<?php

namespace Tests\Feature;

use App\Enums\QuotationStatus;
use App\Models\Business;
use App\Models\BusinessCurrency;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\User;
use App\Services\CurrencyService;
use App\Services\CustomerLedgerService;
use App\Support\Decimal;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Batch 19 — multi-currency.
 *
 * Covers the currency registry/seed, the per-business base currency and its
 * change gate, the enabled-currency and exchange-rate settings routes, and the
 * permanent currency/rate/base_amount snapshots on invoices, quotations,
 * payments and expenses. Asserts the documented semantics:
 *   - base currency defaults to AFN and is always enabled;
 *   - the base is changeable until financial history exists, then locked;
 *   - enabled currencies and rates are strictly business-scoped;
 *   - a foreign document needs a rate at/just before ITS OWN date — a missing
 *     (or future-only) rate is a 422, never a silent record;
 *   - a later rate edit never rewrites an existing snapshot; draft edits
 *     reprice against the new date;
 *   - conversions keep the quotation's currency + rate and just recompute
 *     base_amount (no drift);
 *   - payments copy the invoice's currency and convert at the same rate, and a
 *     request-supplied currency_code is prohibited;
 *   - the customer ledger aggregates AND running balances are denominated in
 *     the business base currency while each row keeps its nominal amount.
 */
class MultiCurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
        $this->seed(CurrencySeeder::class);
    }

    private function makeUser(string $name = 'Currency User'): User
    {
        return User::create(['name' => $name, 'email' => Str::random(10).'@example.test', 'password' => Hash::make('password')]);
    }

    private function makeBusiness(string $name): Business
    {
        return Business::create(['name' => $name]);
    }

    /**
     * @return array{business: Business, membership: BusinessMembership, roles: array<string, Role>}
     */
    private function provision(User $user, string $businessName, string $roleSlug): array
    {
        $business = $this->makeBusiness($businessName);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles[$roleSlug]);

        return [$business, $membership, $roles];
    }

    private function sessionKey(): string
    {
        return config('business.context.session_key');
    }

    private function rebuildContext(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([$this->sessionKey() => $business->id]);
        $this->rebuildContext();
    }

    private function enableModule(Business $business, string $key): void
    {
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => $key],
            ['enabled' => true],
        );
    }

    private function makeCustomer(Business $business, string $name = 'Currency Buyer'): Customer
    {
        $customer = new Customer(['name' => $name, 'company_name' => 'Company', 'email' => Str::random(8).'@example.test']);
        $customer->business_id = $business->id;
        $customer->save();

        return $customer;
    }

    /**
     * Enable a currency for a business and, when a rate is given, record its
     * exchange-rate branch (1 USD = X base units) effective from a date.
     */
    private function enable(Business $business, string $code, ?string $rate = null, string $effectiveDate = '2026-01-01'): void
    {
        BusinessCurrency::create(['business_id' => $business->id, 'currency_code' => $code]);

        if ($rate !== null) {
            $this->rate($business, $code, $rate, $effectiveDate);
        }
    }

    private function rate(Business $business, string $code, string $rate, string $effectiveDate): ExchangeRate
    {
        $branch = new ExchangeRate(['currency_code' => $code, 'rate' => $rate, 'effective_date' => $effectiveDate]);
        $branch->business_id = $business->id;
        $branch->save();

        return $branch;
    }

    private function setBase(User $user, Business $business, string $currency): void
    {
        $this->actIn($user, $business);
        $this->patch('/settings', [
            'regional.timezone' => 'UTC',
            'regional.date_format' => 'Y-m-d',
            'regional.time_format' => 'H:i',
            'regional.locale' => 'en',
            'regional.currency' => $currency,
        ])->assertRedirect(route('settings.index'));
    }

    private function currencyService(): CurrencyService
    {
        return app(CurrencyService::class);
    }

    /**
     * SQLite returns DECIMAL(16,8) cells through the numeric affinity (70.5,
     * not the '70.50000000' string MySQL would return), so a stored rate is
     * asserted through the same normalisation the services apply.
     */
    private function assertRate(mixed $actual, string $expected): void
    {
        $this->assertSame($expected, Decimal::normalize((string) $actual, CurrencyService::RATE_SCALE));
    }

    /**
     * @return array<string, mixed>
     */
    private function invoicePayload(Customer $customer, array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $customer->id,
            'date' => '2026-09-12',
            'status' => 'sent',
            'discount_type' => null,
            'discount_amount' => '',
            'notes' => 'Multi-currency invoice.',
            'items' => [
                ['product_id' => null, 'description' => 'Consulting hours', 'quantity' => '10.0000', 'unit_price' => '120.0000'],
            ],
        ], $overrides);
    }

    private function storeInvoice(User $user, Business $business, Customer $customer, array $overrides = []): Invoice
    {
        $this->actIn($user, $business);
        $this->post(route('invoices.store'), $this->invoicePayload($customer, $overrides))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('invoices.created'));

        return Invoice::query()->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function quotationPayload(Customer $customer, array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $customer->id,
            'date' => '2026-09-12',
            'expiry_date' => '2026-12-31',
            'status' => 'draft',
            'discount_type' => null,
            'discount_amount' => '',
            'notes' => 'Multi-currency quotation.',
            'items' => [
                ['product_id' => null, 'description' => 'Original quote line', 'quantity' => '10.0000', 'unit_price' => '120.0000'],
            ],
        ], $overrides);
    }

    private function storeQuotation(User $user, Business $business, Customer $customer, array $overrides = []): Quotation
    {
        $this->actIn($user, $business);
        $this->post(route('quotations.store'), $this->quotationPayload($customer, $overrides))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('quotations.created'));

        return Quotation::query()->latest('id')->firstOrFail();
    }

    // --- Schema + registry + service defaults ---------------------------------

    public function test_currency_schema_and_config_defaults_are_in_place(): void
    {
        foreach (['currencies', 'business_currencies', 'exchange_rates'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        foreach (['invoices', 'quotations', 'payments', 'expenses'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'currency_code'));
            $this->assertTrue(Schema::hasColumn($table, 'exchange_rate'));
            $this->assertTrue(Schema::hasColumn($table, 'base_amount'));
        }

        $this->assertSame('AFN', config('settings.definitions.regional.currency')['default']);
    }

    public function test_currency_seeder_provides_the_active_registry(): void
    {
        $this->assertSame(11, Currency::query()->count());
        $this->assertSame(11, Currency::query()->active()->count());
        $this->assertSame('Afghan Afghani', Currency::where('code', 'AFN')->value('name'));
        $this->assertSame(2, Currency::where('code', 'USD')->value('decimals'));
    }

    public function test_default_base_currency_is_afn_and_only_afn_is_enabled(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Default Co.', 'owner');
        $this->actIn($user, $business);

        $this->assertSame('AFN', $this->currencyService()->baseCurrency());
        $this->assertSame(['AFN'], $this->currencyService()->enabledCodes());
        $this->assertTrue($this->currencyService()->isEnabled('AFN'));
        $this->assertFalse($this->currencyService()->isEnabled('USD'));
        $this->assertSame('1', $this->currencyService()->resolveRate('AFN'));
        $this->assertSame('1200.0000', $this->currencyService()->toBase('1200.0000', '1'));
    }

    public function test_enabled_currencies_are_scoped_to_the_current_business(): void
    {
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'Alpha Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Co.', 'owner');

        $this->enable($businessA, 'USD');

        $this->actIn($user, $businessA);
        $this->assertContains('USD', $this->currencyService()->enabledCodes());
        $this->assertContains('AFN', $this->currencyService()->enabledCodes());

        $this->actIn($user, $businessB);
        $this->assertNotContains('USD', $this->currencyService()->enabledCodes());
    }

    // --- Base-currency change gate ---------------------------------------------

    public function test_base_currency_can_be_changed_before_financial_history(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Change Co.', 'owner');

        $this->setBase($user, $business, 'USD');

        $this->assertDatabaseHas('settings', ['business_id' => $business->id, 'group' => 'regional', 'key' => 'currency', 'value' => 'USD']);
        $this->assertSame('USD', $this->currencyService()->baseCurrency());
        $this->assertSame(['USD'], $this->currencyService()->enabledCodes());
        $this->assertFalse($this->currencyService()->isEnabled('AFN'));
    }

    public function test_base_currency_is_locked_once_an_invoice_is_finalized(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Locked Co.', 'owner');
        $businessId = $business->id;
        $this->enableModule($business, 'sales');
        $this->storeInvoice($user, $business, $this->makeCustomer($business));

        $this->actIn($user, $business);
        $this->patch('/settings', [
            'regional.timezone' => 'UTC',
            'regional.date_format' => 'Y-m-d',
            'regional.time_format' => 'H:i',
            'regional.locale' => 'en',
            'regional.currency' => 'EUR',
        ])->assertSessionHasErrors(['regional.currency' => __('settings.validation.currency_locked')]);

        $this->assertDatabaseMissing('settings', ['business_id' => $businessId, 'group' => 'regional', 'key' => 'currency', 'value' => 'EUR']);
        $this->assertSame('AFN', $this->currencyService()->baseCurrency());
    }

    public function test_base_currency_is_locked_once_a_non_draft_quotation_exists(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Quote Co.', 'owner');
        $this->enableModule($business, 'sales');
        $this->storeQuotation($user, $business, $this->makeCustomer($business), ['status' => 'sent']);

        $this->actIn($user, $business);
        $this->patch('/settings', [
            'regional.timezone' => 'UTC',
            'regional.date_format' => 'Y-m-d',
            'regional.time_format' => 'H:i',
            'regional.locale' => 'en',
            'regional.currency' => 'EUR',
        ])->assertSessionHasErrors(['regional.currency' => __('settings.validation.currency_locked')]);

        $this->assertSame('AFN', $this->currencyService()->baseCurrency());
    }

    public function test_draft_documents_do_not_lock_the_base_currency(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Draft Co.', 'owner');
        $this->enableModule($business, 'sales');
        $this->storeInvoice($user, $business, $this->makeCustomer($business), ['status' => 'draft']);

        $this->setBase($user, $business, 'USD');

        $this->assertSame('USD', $this->currencyService()->baseCurrency());
    }

    // --- Settings currency management routes -----------------------------------

    public function test_enable_and_disable_currency_honours_context_and_base_is_pinned(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Toggle Co.', 'owner');

        $this->actIn($user, $business);
        $this->post(route('settings.currencies.store'), ['currency_code' => 'USD'])
            ->assertSessionHas('status', __('currencies.status_enabled', ['currency' => 'USD']));
        $this->assertDatabaseHas('business_currencies', ['business_id' => $business->id, 'currency_code' => 'USD']);

        // Doubles and the base currency are refused with a specific message.
        $this->post(route('settings.currencies.store'), ['currency_code' => 'USD'])
            ->assertSessionHasErrors(['currency_code' => __('currencies.validation.already_enabled')]);
        $this->post(route('settings.currencies.store'), ['currency_code' => 'AFN'])
            ->assertSessionHasErrors(['currency_code' => __('currencies.validation.is_base')]);

        // Disabling removes the picker entry but keeps history intact.
        $this->delete(route('settings.currencies.destroy', Currency::where('code', 'USD')->firstOrFail()))
            ->assertSessionHas('status', __('currencies.status_disabled', ['currency' => 'USD']));
        $this->assertDatabaseMissing('business_currencies', ['business_id' => $business->id, 'currency_code' => 'USD']);
        $this->assertNotContains('USD', $this->currencyService()->enabledCodes());
    }

    public function test_currency_settings_routes_require_settings_manage(): void
    {
        $user = $this->makeUser('View Only');
        [$business] = $this->provision($user, 'NoManage Co.', 'viewer');

        $this->actIn($user, $business);
        $this->post(route('settings.currencies.store'), ['currency_code' => 'USD'])->assertForbidden();
        $this->delete(route('settings.currencies.destroy', Currency::where('code', 'USD')->firstOrFail()))->assertForbidden();
        $this->post(route('settings.exchange-rates.store'), ['currency_code' => 'USD', 'rate' => '70.5', 'effective_date' => '2026-01-01'])->assertForbidden();

        $this->assertDatabaseCount('business_currencies', 0);
        $this->assertDatabaseCount('exchange_rates', 0);
    }

    public function test_exchange_rate_store_is_normalized_and_requires_an_enabled_currency(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Rate Co.', 'owner');

        $this->enable($business, 'USD');
        $this->actIn($user, $business);
        $this->post(route('settings.exchange-rates.store'), ['currency_code' => 'USD', 'rate' => '70.5', 'effective_date' => '2026-01-01'])
            ->assertSessionHas('status', __('currencies.status_rate_saved', ['currency' => 'USD']));

        $this->assertDatabaseHas('exchange_rates', [
            'business_id' => $business->id,
            'currency_code' => 'USD',
            'rate' => '70.50000000',
        ]);

        $branch = ExchangeRate::query()->firstOrFail();
        $this->assertSame('2026-01-01', $branch->effective_date->toDateString());

        // A code the business never enabled is refused before any write.
        $this->post(route('settings.exchange-rates.store'), ['currency_code' => 'EUR', 'rate' => '90', 'effective_date' => '2026-01-01'])
            ->assertSessionHasErrors(['currency_code' => __('currencies.validation.not_enabled')]);
        $this->assertDatabaseMissing('exchange_rates', ['business_id' => $business->id, 'currency_code' => 'EUR']);
    }

    public function test_exchange_rate_delete_is_scoped_across_businesses(): void
    {
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'Owner Co.', 'owner');
        [$businessB] = $this->provision($user, 'Tenant Co.', 'owner');

        $this->enable($businessA, 'USD', '70.5', '2026-01-01');
        $rate = ExchangeRate::where('business_id', $businessA->id)->firstOrFail();

        // The owner of A can delete its own rate.
        $this->actIn($user, $businessA);
        $this->delete(route('settings.exchange-rates.destroy', $rate))
            ->assertSessionHas('status', __('currencies.status_rate_deleted', ['currency' => 'USD']));
        $this->assertDatabaseCount('exchange_rates', 0);

        // A rate of A is unreachable (404) from B — route-model binding scope.
        $this->rate($businessA, 'USD', '70.5', '2026-01-01');
        $rate = ExchangeRate::where('business_id', $businessA->id)->firstOrFail();
        $this->actIn($user, $businessB);
        $this->delete(route('settings.exchange-rates.destroy', $rate))->assertNotFound();
        $this->assertDatabaseCount('exchange_rates', 1);
    }

    // --- Invoice snapshots -------------------------------------------------------

    public function test_invoice_records_the_foreign_currency_snapshot(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Usd Co.', 'owner');
        $this->enableModule($business, 'sales');
        $this->enable($business, 'USD', '70.5', '2026-01-01');

        $invoice = $this->storeInvoice($user, $business, $this->makeCustomer($business), ['currency_code' => 'USD']);

        $this->assertSame('USD', $invoice->currency_code);
        $this->assertRate($invoice->exchange_rate, '70.50000000');
        $this->assertSame('1200.0000', (string) $invoice->total);
        $this->assertSame('84600.0000', $invoice->base_amount);
    }

    public function test_invoice_without_currency_defaults_to_the_base_currency(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Plain Co.', 'owner');
        $this->enableModule($business, 'sales');

        $invoice = $this->storeInvoice($user, $business, $this->makeCustomer($business));

        $this->assertSame('AFN', $invoice->currency_code);
        $this->assertSame('1', (string) $invoice->exchange_rate);
        $this->assertSame('1200.0000', $invoice->base_amount);
    }

    public function test_foreign_currency_without_a_rate_is_rejected_on_currency_code(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'NoRate Co.', 'owner');
        $this->enableModule($business, 'sales');
        $this->enable($business, 'USD');

        $this->actIn($user, $business);
        $this->post(route('invoices.store'), $this->invoicePayload($this->makeCustomer($business), ['currency_code' => 'USD']))
            ->assertSessionHasErrors(['currency_code' => __('currencies.validation.rate_missing', ['currency' => 'USD', 'date' => '2026-09-12'])]);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_rate_resolution_uses_the_branch_at_or_before_the_document_date(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Branch Co.', 'owner');
        $this->enableModule($business, 'sales');
        $this->enable($business, 'USD');
        $this->rate($business, 'USD', '70.5', '2026-01-01');
        $this->rate($business, 'USD', '72.9', '2026-06-01');
        $customer = $this->makeCustomer($business);

        $later = $this->storeInvoice($user, $business, $customer, ['currency_code' => 'USD', 'date' => '2026-09-12']);
        $this->assertRate($later->exchange_rate, '72.90000000');
        $this->assertSame('87480.0000', $later->base_amount); // 1200 * 72.9

        $earlier = $this->storeInvoice($user, $business, $customer, ['currency_code' => 'USD', 'date' => '2026-03-01']);
        $this->assertRate($earlier->exchange_rate, '70.50000000');
        $this->assertSame('84600.0000', $earlier->base_amount);

        // A date before the first branch (future-only rates) is a 422.
        $this->actIn($user, $business);
        $this->post(route('invoices.store'), $this->invoicePayload($customer, ['currency_code' => 'USD', 'date' => '2025-06-01']))
            ->assertSessionHasErrors('currency_code');
    }

    public function test_draft_edit_recomputes_the_snapshot_for_the_new_date(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Reprice Co.', 'owner');
        $this->enableModule($business, 'sales');
        $this->enable($business, 'USD');
        $this->rate($business, 'USD', '70.5', '2026-01-01');
        $this->rate($business, 'USD', '72.9', '2026-06-01');
        $customer = $this->makeCustomer($business);

        $invoice = $this->storeInvoice($user, $business, $customer, ['currency_code' => 'USD', 'date' => '2026-03-01', 'status' => 'draft']);
        $this->assertRate($invoice->exchange_rate, '70.50000000');
        $this->assertSame('84600.0000', $invoice->base_amount);

        $this->actIn($user, $business);
        $this->patch(route('invoices.update', $invoice), $this->invoicePayload($customer, [
            'currency_code' => 'USD',
            'date' => '2026-09-12',
            'status' => 'draft',
        ]))->assertSessionHas('status', __('invoices.updated'));

        $invoice->refresh();
        $this->assertRate($invoice->exchange_rate, '72.90000000');
        $this->assertSame('87480.0000', $invoice->base_amount);
    }

    public function test_invoice_rejects_a_currency_not_enabled_for_this_business(): void
    {
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'Owner Co.', 'owner');
        [$businessB] = $this->provision($user, 'Foreign Co.', 'owner');
        $this->enableModule($businessA, 'sales');
        $this->enableModule($businessB, 'sales');
        $this->enable($businessA, 'USD', '70.5', '2026-01-01');

        $this->actIn($user, $businessB);
        $this->post(route('invoices.store'), $this->invoicePayload($this->makeCustomer($businessB), ['currency_code' => 'USD']))
            ->assertSessionHasErrors(['currency_code' => __('currencies.validation.invalid')]);
        $this->assertDatabaseCount('invoices', 0);
    }

    // --- Quotation + conversion (no-drift) ---------------------------------------

    public function test_conversion_keeps_the_quotation_currency_and_rate_without_drift(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Convert Co.', 'owner');
        $this->enableModule($business, 'sales');
        $this->enable($business, 'USD', '70.5', '2026-01-01');
        $customer = $this->makeCustomer($business);

        $quotation = $this->storeQuotation($user, $business, $customer, ['currency_code' => 'USD', 'status' => 'sent']);
        $this->assertSame('USD', $quotation->currency_code);
        $this->assertRate($quotation->exchange_rate, '70.50000000');
        $this->assertSame('84600.0000', $quotation->base_amount);

        $this->actIn($user, $business);
        $this->post(route('quotations.convert', $quotation))->assertSessionHas('status', __('invoices.converted'));

        $invoice = Invoice::query()->where('quotation_id', $quotation->id)->firstOrFail();
        $this->assertSame('USD', $invoice->currency_code);
        $this->assertRate($invoice->exchange_rate, '70.50000000');
        $this->assertSame('1200.0000', (string) $invoice->total);
        $this->assertSame('84600.0000', $invoice->base_amount);
        $this->assertSame(QuotationStatus::Converted, $quotation->fresh()->status);
    }

    // --- Payments -----------------------------------------------------------------

    public function test_payment_copies_the_invoice_currency_and_converts_at_the_same_rate(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Pay Co.', 'owner');
        $this->enableModule($business, 'sales');
        $this->enable($business, 'USD', '70.5', '2026-01-01');
        $invoice = $this->storeInvoice($user, $business, $this->makeCustomer($business), ['currency_code' => 'USD']);

        $this->actIn($user, $business);
        $this->post(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => '500.0000',
            'payment_date' => '2026-09-12',
            'payment_method' => 'bank_transfer',
            'reference' => 'TXN-USD-1',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', __('payments.created'));

        $payment = Payment::query()->latest('id')->firstOrFail();
        $this->assertSame('USD', $payment->currency_code);
        $this->assertRate($payment->exchange_rate, '70.50000000');
        $this->assertSame('35250.0000', $payment->base_amount); // 500 * 70.5

        $invoice->refresh();
        $this->assertSame('500.0000', $invoice->amount_paid);
        $this->assertSame('700.0000', $invoice->amount_due);
    }

    public function test_payment_rejects_a_request_supplied_currency_code(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'NoSelect Co.', 'owner');
        $this->enableModule($business, 'sales');
        $this->enable($business, 'USD', '70.5', '2026-01-01');
        $invoice = $this->storeInvoice($user, $business, $this->makeCustomer($business), ['currency_code' => 'USD']);

        $this->actIn($user, $business);
        $this->post(route('payments.store'), [
            'invoice_id' => $invoice->id,
            'amount' => '500.0000',
            'payment_date' => '2026-09-12',
            'payment_method' => 'bank_transfer',
            'currency_code' => 'AFN',
            'reference' => null,
        ])->assertSessionHasErrors(['currency_code' => __('currencies.validation.prohibited')]);

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    // --- Expenses ------------------------------------------------------------------

    public function test_expense_records_the_foreign_currency_snapshot(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Spend Co.', 'owner');
        $this->enableModule($business, 'expenses');
        $this->enable($business, 'USD', '70.5', '2026-01-01');

        $this->actIn($user, $business);
        $this->post(route('expenses.store'), [
            'category_id' => null,
            'expense_date' => '2026-09-13',
            'amount' => '100.0000',
            'payment_method' => 'cash',
            'reference' => 'EXP-USD-1',
            'vendor' => 'Office Supplies Co.',
            'notes' => null,
            'currency_code' => 'USD',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', __('expenses.created'));

        $expense = Expense::query()->latest('id')->firstOrFail();
        $this->assertSame('USD', $expense->currency_code);
        $this->assertRate($expense->exchange_rate, '70.50000000');
        $this->assertSame('7050.0000', $expense->base_amount); // 100 * 70.5
    }

    public function test_expense_defaults_to_base_and_rejects_foreign_not_enabled(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Spend2 Co.', 'owner');
        $this->enableModule($business, 'expenses');

        $this->actIn($user, $business);
        $this->post(route('expenses.store'), [
            'category_id' => null,
            'expense_date' => '2026-09-13',
            'amount' => '100.0000',
            'payment_method' => 'cash',
            'reference' => null,
            'vendor' => null,
            'notes' => null,
        ])->assertSessionHasNoErrors();

        $expense = Expense::query()->latest('id')->firstOrFail();
        $this->assertSame('AFN', $expense->currency_code);
        $this->assertSame('1', (string) $expense->exchange_rate);
        $this->assertSame('100.0000', $expense->base_amount);

        // EUR is not enabled for this business, so the picker rule refuses it.
        $this->post(route('expenses.store'), [
            'category_id' => null,
            'expense_date' => '2026-09-13',
            'amount' => '100.0000',
            'payment_method' => 'cash',
            'reference' => null,
            'vendor' => null,
            'notes' => null,
            'currency_code' => 'EUR',
        ])->assertSessionHasErrors(['currency_code' => __('currencies.validation.invalid')]);
    }

    public function test_expense_report_totals_in_base_currency(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Report FX Co.', 'owner');
        $this->enableModule($business, 'expenses');
        $this->enable($business, 'USD', '70.5', '2026-01-01');

        $this->actIn($user, $business);

        $this->post(route('expenses.store'), [
            'category_id' => null,
            'expense_date' => '2026-09-13',
            'amount' => '100.0000',
            'payment_method' => 'cash',
            'reference' => null,
            'vendor' => 'QC Hardware',
            'notes' => null,
            'currency_code' => 'USD',
        ])->assertSessionHasNoErrors();

        $this->post(route('expenses.store'), [
            'category_id' => null,
            'expense_date' => '2026-09-14',
            'amount' => '50.0000',
            'payment_method' => 'cash',
            'reference' => null,
            'vendor' => 'Local Supplies',
            'notes' => null,
        ])->assertSessionHasNoErrors();

        // 100 USD @ 70.5 = 7050 AFN + 50 AFN => 7100.0000 AFN, never a naive 150.
        $this->get(route('expenses.report'))
            ->assertOk()
            ->assertSee('7100.0000')
            ->assertDontSee('150.0000');
    }

    // --- Ledger aggregates in base currency ------------------------------------------

    public function test_mixed_currency_ledger_aggregates_and_running_balance_are_in_base(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Ledger Co.', 'owner');
        $this->enableModule($business, 'sales');
        $this->enableModule($business, 'customers');
        $this->enable($business, 'USD', '70.5', '2026-01-01');
        $customer = $this->makeCustomer($business);

        // AFN invoice (rate 1): total 500.0000 -> base 500.0000.
        $afnInvoice = $this->storeInvoice($user, $business, $customer, [
            'date' => '2026-09-01',
            'items' => [['product_id' => null, 'description' => 'AFN line', 'quantity' => '5.0000', 'unit_price' => '100.0000']],
        ]);

        // USD invoice (rate 70.5): total 100.0000 -> base 7050.0000.
        $usdInvoice = $this->storeInvoice($user, $business, $customer, [
            'date' => '2026-09-02',
            'currency_code' => 'USD',
            'items' => [['product_id' => null, 'description' => 'USD line', 'quantity' => '1.0000', 'unit_price' => '100.0000']],
        ]);

        // Payments: 200 base rate-1 on the AFN invoice, 100 USD (7050 base) on the USD one.
        foreach ([
            ['invoice' => $afnInvoice, 'amount' => '200.0000'],
            ['invoice' => $usdInvoice, 'amount' => '100.0000'],
        ] as $row) {
            $this->actIn($user, $business);
            $this->post(route('payments.store'), [
                'invoice_id' => $row['invoice']->id,
                'amount' => $row['amount'],
                'payment_date' => '2026-09-05',
                'payment_method' => 'cash',
                'reference' => null,
            ])->assertSessionHasNoErrors();
        }

        $this->actIn($user, $business);
        $summary = app(CustomerLedgerService::class)->summary($customer);
        $this->assertSame('0.0000', $summary['opening_balance']);
        $this->assertSame('7550.0000', $summary['total_invoiced']);   // 500 + 7050
        $this->assertSame('7250.0000', $summary['total_paid']);      // 200 + 7050
        $this->assertSame('300.0000', $summary['outstanding_balance']);

        $rows = app(CustomerLedgerService::class)->ledger($customer)['rows'];
        $invoiceRows = array_values(array_filter($rows, fn (array $r): bool => $r['type'] === 'invoice'));
        $this->assertCount(2, $invoiceRows);
        $this->assertSame('AFN', $invoiceRows[0]['currency_code']);
        $this->assertSame('500.0000', $invoiceRows[0]['debit']);
        $this->assertSame('500.0000', $invoiceRows[0]['base_debit']);
        $this->assertSame('USD', $invoiceRows[1]['currency_code']);
        $this->assertSame('100.0000', $invoiceRows[1]['debit']);
        $this->assertSame('7050.0000', $invoiceRows[1]['base_debit']);

        // The ledger page renders the currency codes and the base-currency note.
        $this->get(route('customers.ledger', $customer))
            ->assertOk()
            ->assertSee('USD')
            ->assertSee(__('customers.ledger.base_currency_note', ['currency' => 'AFN']));
    }

    // --- Pickers are scoped per business ----------------------------------------------

    public function test_invoice_create_picker_reflects_only_enabled_currencies(): void
    {
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'Picker A', 'owner');
        [$businessB] = $this->provision($user, 'Picker B', 'owner');
        $this->enableModule($businessA, 'sales');
        $this->enableModule($businessB, 'sales');
        $this->enable($businessA, 'USD');

        $this->actIn($user, $businessA);
        $this->get(route('invoices.create'))
            ->assertOk()
            ->assertSee('value="AFN" selected', false)
            ->assertSee('value="USD"', false);

        $this->actIn($user, $businessB);
        $this->get(route('invoices.create'))
            ->assertOk()
            ->assertSee('value="AFN" selected', false)
            ->assertDontSee('value="USD"', false);
    }

    public function test_settings_page_renders_the_currency_card_for_the_current_business(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Settings Cur Co.', 'owner');
        $this->enable($business, 'USD', '70.5', '2026-01-01');

        $this->actIn($user, $business);
        $this->get(route('settings.index'))
            ->assertOk()
            ->assertSee(__('settings.currencies'))
            ->assertSee(__('settings.base_currency_badge'))
            ->assertSee('USD')
            ->assertSee(__('settings.exchange_rates'))
            ->assertSee('70.50000000');
    }
}
