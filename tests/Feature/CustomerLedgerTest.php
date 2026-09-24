<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\CustomerLedgerService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Customer ledger & statements (Batch 18).
 *
 * The ledger is a calculated read model over the authoritative Batch 15/16
 * records — finalized (non-draft) invoice totals and active payment
 * allocations — never a new financial table and never the reconciled
 * amount_paid/amount_due caches. Amounts are exact Decimal (BCMath) 4-dp
 * strings; negatives/zero-opening and reversal semantics follow the approved
 * option: a reversed payment keeps its original credit row (marked reversed)
 * plus an explicit reversal debit row, and never reduces the outstanding
 * balance.
 *
 * HTTP routes /customers/{customer}/ledger and /statement sit under
 * module:customers + permission:customers.view (no new permission), reusing
 * the same tenant-scoped route-model binding as the rest of the customer
 * module — cross-business or soft-deleted customers 404.
 */
class CustomerLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);

        $this->businesses = [];
    }

    /** @var list<Business> */
    private array $businesses = [];

    private function makeUser(string $name = 'Ledger User'): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function makeBusiness(string $name): Business
    {
        $business = Business::create(['name' => $name]);
        $this->businesses[] = $business;

        return $business;
    }

    private function makeCustomer(Business $business, array $attributes = []): Customer
    {
        $customer = new Customer(array_merge([
            'name' => 'Customer '.Str::random(5),
            'company_name' => 'Company '.Str::random(5),
            'email' => Str::random(8).'@example.test',
        ], $attributes));
        $customer->business_id = $business->id;
        $customer->save();

        return $customer;
    }

    private function sessionKey(): string
    {
        return config('business.context.session_key');
    }

    private function rebuildContext(): void
    {
        $this->app->forgetScopedInstances();
    }

    public function flushSession(): void
    {
        if (session()->isStarted()) {
            session()->flush();
        }
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
        $this->rebuildContext();
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => $key],
            ['enabled' => true],
        );
    }

    /**
     * @return array{business: Business, membership: BusinessMembership}
     */
    private function provision(User $user, string $businessName, string $role = 'admin'): array
    {
        $business = $this->makeBusiness($businessName);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();

        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles[$role]);

        return [$business, $membership];
    }

    /**
     * A validated, well-formed invoice payload mirroring the payment fixture
     * shape (snapshot qty + unit_price).
     *
     * @return array<string, mixed>
     */
    private function payload(Customer $customer, array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $customer->id,
            'date' => '2026-09-12',
            'status' => 'draft',
            'discount_type' => null,
            'discount_amount' => '',
            'notes' => 'Ledger fixture invoice.',
            'items' => [
                [
                    'product_id' => null,
                    'description' => 'Consulting hours',
                    'quantity' => '10.0000',
                    'unit_price' => '120.0000',
                ],
            ],
        ], $overrides);
    }

    /**
     * Store an invoice through the real route and finalize it to `sent`.
     */
    private function storeInvoice(Business $business, Customer $customer, array $overrides = []): Invoice
    {
        $this->post('/invoices', $this->payload($customer, $overrides))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $invoice = Invoice::query()->latest('id')->first();
        $this->assertNotNull($invoice);

        $invoice->status = InvoiceStatus::Sent;
        $invoice->save();

        return $invoice;
    }

    /**
     * Record a payment against a sent invoice through the real payment route.
     */
    private function storePayment(Business $business, Invoice $invoice, string $amount): Payment
    {
        $this->post('/payments', [
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'payment_date' => '2026-09-12',
            'payment_method' => 'bank_transfer',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $payment = Payment::query()->latest('id')->first();
        $this->assertNotNull($payment);

        return $payment;
    }

    private function service(): CustomerLedgerService
    {
        return $this->app->make(CustomerLedgerService::class);
    }

    private function provisionSalesAndCustomers(Business $business): void
    {
        $this->enableModule($business, 'sales');
        $this->enableModule($business, 'customers');
    }

    public function test_schema_has_opening_balance_columns(): void
    {
        $this->assertTrue(Schema::hasTable('customers'));
        $this->assertTrue(Schema::hasColumns('customers', ['opening_balance', 'opening_balance_date']));
        $this->assertTrue(Schema::hasColumns('payments', ['party_type', 'party_id', 'reversed_at']));
    }

    public function test_fresh_customer_has_zero_balance(): void
    {
        $user = $this->makeUser('Zero User');
        [$business] = $this->provision($user, 'Ledger Zero Co');
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $summary = $this->service()->summary($customer);

        $this->assertSame('0.0000', $summary['opening_balance']);
        $this->assertSame('0.0000', $summary['total_invoiced']);
        $this->assertSame('0.0000', $summary['total_paid']);
        $this->assertSame('0.0000', $summary['outstanding_balance']);
    }

    public function test_opening_balance_only_is_exact(): void
    {
        $user = $this->makeUser('Opening User');
        [$business] = $this->provision($user, 'Ledger Opening Co');
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business, ['opening_balance' => '250.0000', 'opening_balance_date' => '2026-01-15']);
        $summary = $this->service()->summary($customer);

        $this->assertSame('250.0000', $summary['opening_balance']);
        $this->assertSame('0.0000', $summary['total_invoiced']);
        $this->assertSame('250.0000', $summary['outstanding_balance']);
    }

    public function test_single_sent_invoice_adds_to_outstanding(): void
    {
        $user = $this->makeUser('Invoice User');
        [$business] = $this->provision($user, 'Ledger Invoice Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $this->storeInvoice($business, $customer, ['date' => '2026-09-10']);

        $summary = $this->service()->summary($customer);
        $this->assertSame('1200.0000', $summary['total_invoiced']);
        $this->assertSame('1200.0000', $summary['outstanding_balance']);
    }

    public function test_draft_invoice_is_excluded_from_balance_and_ledger(): void
    {
        $user = $this->makeUser('Draft User');
        [$business] = $this->provision($user, 'Ledger Draft Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        // Stored through the route as a draft, never finalized.
        $this->post('/invoices', $this->payload($customer))->assertSessionHasNoErrors();

        $summary = $this->service()->summary($customer);
        $this->assertSame('0.0000', $summary['total_invoiced']);
        $this->assertSame('0.0000', $summary['outstanding_balance']);

        $result = $this->service()->ledger($customer);
        foreach ($result['rows'] as $row) {
            $this->assertNotSame('invoice', $row['type']);
        }
    }

    public function test_full_payment_zeroes_outstanding(): void
    {
        $user = $this->makeUser('Paid User');
        [$business] = $this->provision($user, 'Ledger Paid Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $invoice = $this->storeInvoice($business, $customer);
        $this->storePayment($business, $invoice, $invoice->total);

        $summary = $this->service()->summary($customer);
        $this->assertSame('1200.0000', $summary['total_invoiced']);
        $this->assertSame('1200.0000', $summary['total_paid']);
        $this->assertSame('0.0000', $summary['outstanding_balance']);
    }

    public function test_partial_payment_leaves_due_balance(): void
    {
        $user = $this->makeUser('Partial User');
        [$business] = $this->provision($user, 'Ledger Partial Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $invoice = $this->storeInvoice($business, $customer);
        $this->storePayment($business, $invoice, '500.0000');

        $summary = $this->service()->summary($customer);
        $this->assertSame('500.0000', $summary['total_paid']);
        $this->assertSame('700.0000', $summary['outstanding_balance']);
    }

    public function test_multiple_invoices_and_payments_carry_running_balance(): void
    {
        $user = $this->makeUser('Running User');
        [$business] = $this->provision($user, 'Ledger Running Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business, ['opening_balance' => '100.0000', 'opening_balance_date' => '2026-08-01']);

        $invoiceOne = $this->storeInvoice($business, $customer, ['date' => '2026-09-10']);
        $this->storePayment($business, $invoiceOne, '400.0000');
        $this->storeInvoice($business, $customer, ['date' => '2026-09-15']);

        // opening 100 + invoice 1200 + invoice 1200 - payment 400 = 2100
        $summary = $this->service()->summary($customer);
        $this->assertSame('2400.0000', $summary['total_invoiced']);
        $this->assertSame('400.0000', $summary['total_paid']);
        $this->assertSame('2100.0000', $summary['outstanding_balance']);

        $result = $this->service()->ledger($customer);
        $balances = array_column($result['rows'], 'balance');
        $this->assertSame('100.0000', $result['rows'][0]['balance']);       // opening
        $this->assertSame('1300.0000', $balances[1]);                       // + invoice
        $this->assertSame('900.0000', $balances[2]);                        // - payment
        $this->assertSame('2100.0000', $balances[3]);                       // + invoice
        $this->assertSame('2100.0000', $result['closing_balance']);
        $this->assertNull($result['brought_forward']);
        $this->assertTrue($result['show_running_balance']);
    }

    public function test_reversal_restores_balance_and_renders_reversal_row(): void
    {
        $user = $this->makeUser('Reversal User');
        [$business] = $this->provision($user, 'Ledger Reversal Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $invoice = $this->storeInvoice($business, $customer);
        $payment = $this->storePayment($business, $invoice, '500.0000');

        $this->post("/payments/{$payment->id}/reverse", ['reason' => 'Customer requested refund'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $summary = $this->service()->summary($customer);
        $this->assertSame('0.0000', $summary['total_paid']);
        $this->assertSame('1200.0000', $summary['outstanding_balance']);

        // The reversal presentation: the original credit row (marked reversed)
        // plus an explicit reversal debit row.
        $types = array_column($this->service()->ledger($customer)['rows'], 'type');
        $this->assertContains('payment', $types);
        $this->assertContains('reversal', $types);
    }

    public function test_outstanding_uses_active_allocations_only(): void
    {
        $user = $this->makeUser('Active Only User');
        [$business] = $this->provision($user, 'Ledger ActiveOnly Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $invoice = $this->storeInvoice($business, $customer);
        $this->storePayment($business, $invoice, '700.0000');

        // Reversed payments must not reduce the balance; only the 700 stays.
        $payment = $this->storePayment($business, $invoice, '500.0000');
        $this->post("/payments/{$payment->id}/reverse", ['reason' => 'Written off by mistake'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $summary = $this->service()->summary($customer);
        $this->assertSame('700.0000', $summary['total_paid']);
        $this->assertSame('500.0000', $summary['outstanding_balance']);
    }

    public function test_date_from_adds_brought_forward_row(): void
    {
        $user = $this->makeUser('Period User');
        [$business] = $this->provision($user, 'Ledger Period Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business, ['opening_balance' => '250.0000', 'opening_balance_date' => '2026-07-01']);
        $invoice = $this->storeInvoice($business, $customer, ['date' => '2026-09-10']);
        $this->storePayment($business, $invoice, '500.0000');

        $result = $this->service()->ledger($customer, ['date_from' => '2026-09-01']);

        $this->assertNotNull($result['brought_forward']);
        $this->assertSame('250.0000', $result['brought_forward']);
        $this->assertSame('brought_forward', $result['rows'][0]['type']);
        // b/f 250 + invoice 1200 - payment 500 = 950 (== all-time outstanding)
        $this->assertSame('950.0000', $result['rows'][array_key_last($result['rows'])]['balance']);
        $this->assertSame('950.0000', $result['closing_balance']);
        $this->assertTrue($result['show_running_balance']);
        $this->assertTrue($result['has_period_filter']);
    }

    public function test_date_range_filters_entries_without_losing_closing_label(): void
    {
        $user = $this->makeUser('Range User');
        [$business] = $this->provision($user, 'Ledger Range Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business, ['opening_balance' => '250.0000', 'opening_balance_date' => '2026-07-01']);
        $invoice = $this->storeInvoice($business, $customer, ['date' => '2026-09-10']);
        $this->storePayment($business, $invoice, '500.0000');

        // A period that contains only the opening and invoice — the payment date
        // (09-12) falls outside it.
        $result = $this->service()->ledger($customer, ['date_from' => '2026-08-01', 'date_to' => '2026-09-11']);

        $this->assertSame('250.0000', $result['brought_forward']);
        $this->assertSame('1450.0000', $result['closing_balance']);
        $visible = array_column($result['rows'], 'type');
        $this->assertContains('brought_forward', $visible);
        $this->assertContains('invoice', $visible);
        $this->assertNotContains('payment', $visible);
        $this->assertTrue($result['has_period_filter']);
    }

    public function test_search_filter_hides_running_balance(): void
    {
        $user = $this->makeUser('Search User');
        [$business] = $this->provision($user, 'Ledger Search Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business, ['opening_balance' => '250.0000']);
        $invoice = $this->storeInvoice($business, $customer);
        $this->storePayment($business, $invoice, '500.0000');

        $result = $this->service()->ledger($customer, ['search' => 'PAY']);

        $this->assertFalse($result['show_running_balance']);
        $this->assertFalse($result['has_period_filter']);
        foreach ($result['rows'] as $row) {
            $this->assertStringContainsString('PAY', $row['reference']);
        }
    }

    public function test_type_filter_isolates_payment_rows(): void
    {
        $user = $this->makeUser('Type User');
        [$business] = $this->provision($user, 'Ledger Type Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $invoice = $this->storeInvoice($business, $customer);
        $payment = $this->storePayment($business, $invoice, '300.0000');
        $this->post("/payments/{$payment->id}/reverse", ['reason' => 'Test reversal'])
            ->assertSessionHasNoErrors();

        $result = $this->service()->ledger($customer, ['type' => 'payment']);

        $this->assertFalse($result['show_running_balance']);
        foreach ($result['rows'] as $row) {
            $this->assertContains($row['type'], ['payment', 'reversal']);
        }
    }

    public function test_cross_business_data_never_mixes(): void
    {
        $user = $this->makeUser('Tenant User');
        [$businessA] = $this->provision($user, 'Ledger Tenant A Co');
        [$businessB] = $this->provision($user, 'Ledger Tenant B Co');
        $this->provisionSalesAndCustomers($businessA);
        $this->provisionSalesAndCustomers($businessB);

        $this->actIn($user, $businessA);
        $customerA = $this->makeCustomer($businessA);
        $this->storeInvoice($businessA, $customerA);

        // In business A the customer's own balance is its own data, exactly:
        // A knows nothing about B (B does not even exist yet).
        $summaryA = $this->service()->summary($customerA);
        $this->assertSame('1200.0000', $summaryA['total_invoiced']);
        $this->assertSame('1200.0000', $summaryA['outstanding_balance']);

        $this->actIn($user, $businessB);
        $customerB = $this->makeCustomer($businessB);

        // The same service in business B sees only B's data for B's customer —
        // A's invoice does not leak into B's balance.
        $summaryB = $this->service()->summary($customerB);
        $this->assertSame('0.0000', $summaryB['total_invoiced']);
        $this->assertSame('0.0000', $summaryB['outstanding_balance']);

        // And once the context is B, A's own customer/invoice is unreachable:
        // the Customer global scope hides it and the invoice relation (also
        // business-scoped) resolves to nothing, so even a direct service call
        // cannot read A's financials from inside B.
        $this->assertSame(0, Customer::query()->whereKey($customerA->id)->count());
        $this->assertSame('0.0000', $this->service()->summary($customerA)['total_invoiced']);
        $this->assertSame(1, Customer::query()->whereKey($customerB->id)->count());
    }

    public function test_cross_business_ledger_route_returns_404(): void
    {
        $user = $this->makeUser('Cross 404 User');
        [$businessA] = $this->provision($user, 'Ledger Cross A Co');
        [$businessB] = $this->provision($user, 'Ledger Cross B Co');
        $this->provisionSalesAndCustomers($businessA);
        $this->provisionSalesAndCustomers($businessB);

        $this->actIn($user, $businessA);
        $customerA = $this->makeCustomer($businessA);

        $this->actIn($user, $businessB);

        $this->get("/customers/{$customerA->id}/ledger")->assertNotFound();
        $this->get("/customers/{$customerA->id}/statement")->assertNotFound();
    }

    public function test_soft_deleted_customer_ledger_returns_404(): void
    {
        $user = $this->makeUser('Deleted User');
        [$business] = $this->provision($user, 'Ledger Deleted Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $customer->delete();

        $this->get("/customers/{$customer->id}/ledger")->assertNotFound();
    }

    public function test_viewer_can_read_ledger_and_statement_pages(): void
    {
        $user = $this->makeUser('Viewer Holder');
        [$business] = $this->provision($user, 'Ledger Viewer Co', 'viewer');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business, ['opening_balance' => '25.0000', 'opening_balance_date' => '2026-09-01']);

        $this->get("/customers/{$customer->id}/ledger")
            ->assertOk()
            ->assertSee($customer->name)
            ->assertSee(__('customers.ledger.outstanding_balance'));

        $this->get("/customers/{$customer->id}/statement")
            ->assertOk()
            ->assertSee($customer->name);

        // A viewer can see the read-only balance cards on the customer page but
        // not the edit entry point.
        $this->get("/customers/{$customer->id}")->assertOk()->assertSee($customer->name);
    }

    public function test_viewer_cannot_update_opening_balance(): void
    {
        $user = $this->makeUser('Viewer Writer');
        [$business] = $this->provision($user, 'Ledger ViewerWrite Co', 'viewer');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);

        $this->patch("/customers/{$customer->id}", [
            'name' => 'Renamed',
            'opening_balance' => '100.0000',
        ])->assertForbidden();

        $customer->refresh();
        $this->assertSame('0.0000', $customer->opening_balance);
    }

    public function test_admin_can_update_opening_balance_through_form(): void
    {
        $user = $this->makeUser('Balance Writer');
        [$business] = $this->provision($user, 'Ledger BalanceWrite Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);

        $this->patch("/customers/{$customer->id}", [
            'name' => $customer->name,
            'opening_balance' => '250.5',
            'opening_balance_date' => '2026-09-01',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $customer->refresh();
        $this->assertSame('250.5000', $customer->opening_balance);
        $this->assertSame('2026-09-01', $customer->opening_balance_date?->format('Y-m-d'));

        // The edit form must pre-fill both fields with browser-valid values
        // (a raw ISO datetime would be dropped by a <input type="date">).
        $this->get("/customers/{$customer->id}/edit")
            ->assertOk()
            ->assertSee('value="250.5000"', false)
            ->assertSee('value="2026-09-01"', false);

        $summary = $this->service()->summary($customer);
        $this->assertSame('250.5000', $summary['outstanding_balance']);
    }

    public function test_negative_opening_balance_is_rejected(): void
    {
        $user = $this->makeUser('Negative User');
        [$business] = $this->provision($user, 'Ledger Negative Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);

        $this->patch("/customers/{$customer->id}", [
            'name' => $customer->name,
            'opening_balance' => '-5.0000',
        ])->assertSessionHasErrors('opening_balance');

        $customer->refresh();
        $this->assertSame('0.0000', $customer->opening_balance);
    }

    public function test_malformed_opening_balance_is_rejected_with_validation_error(): void
    {
        $user = $this->makeUser('Malformed User');
        [$business] = $this->provision($user, 'Ledger Malformed Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        // Garbage that would crash Decimal::normalize must not 500 — it must
        // surface as a normal validation error.
        $this->patch("/customers/{$this->makeCustomer($business)->id}", [
            'name' => 'Garbage',
            'opening_balance' => 'not-a-number',
        ])->assertSessionHasErrors('opening_balance');
    }

    public function test_ledger_preserves_decimal_exactness(): void
    {
        $user = $this->makeUser('Exactness User');
        [$business] = $this->provision($user, 'Ledger Exact Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        // 33.3333 x 3 = 99.9999 — exactly a 4-dp value with no float artifact.
        $invoice = $this->storeInvoice($business, $customer, [
            'items' => [
                [
                    'product_id' => null,
                    'description' => 'Exact line',
                    'quantity' => '3.0000',
                    'unit_price' => '33.3333',
                ],
            ],
        ]);

        $this->assertSame('99.9999', $invoice->total);
        $this->assertSame('99.9999', $this->service()->totalInvoiced($customer));
        $this->assertSame('99.9999', $this->service()->outstandingBalance($customer));
    }

    public function test_ledger_route_respects_query_filters(): void
    {
        $user = $this->makeUser('Route Filter User');
        [$business] = $this->provision($user, 'Ledger RouteFilter Co');
        $this->provisionSalesAndCustomers($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $invoice = $this->storeInvoice($business, $customer);
        $this->storePayment($business, $invoice, '500.0000');

        $this->get("/customers/{$customer->id}/ledger?type=payment")
            ->assertOk()
            ->assertSee(__('customers.ledger.only_payments'));

        $this->get("/customers/{$customer->id}/statement?date_from=2026-01-01&date_to=2026-12-31")
            ->assertOk();
    }
}
