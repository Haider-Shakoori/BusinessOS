<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\ProductType;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tax;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Payment lifecycle (Batch 16).
 *
 * A payment belongs to a business (sales module), carries an immutable
 * per-business PAY number issued inside the same transaction as the insert by
 * the Batch 13 DocumentNumberService (DocumentType::Pay), and settles a single
 * customer payable invoice through a one-to-many payment_allocations join.
 * Only a payable (sent/partially paid) invoice can be paid; drafts are never
 * payable. Overpayment is rejected; exact settlement fully pays and closes the
 * invoice (InvoiceStatus::Paid, paid_at stamped).
 *
 * Reversals are soft and auditable: the original Payment row and its PAY
 * number are kept forever (the number is never reused), the row is flagged
 * reversed (status + reversed_at/reversed_by/reversal_reason) and its active
 * allocation is withdrawn (allocation keeps reversed_* markers; the invoice
 * balance is restored). A reversal can only succeed once - a second reversal
 * of the same payment rejects.
 *
 * Permissions: payments.view (view), payments.create (record), payments.reverse
 * (reverse). The default Business Settings module key is used to scope every
 * payment to its business, and every invoice fixture is stored through the same
 * route + payload shape as the (green) InvoiceTest so the payment assertions
 * operate on proven, non-zero amounts.
 */
class PaymentTest extends TestCase
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

    private function makeUser(string $name = 'Payment User'): User
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

    private function makeProduct(Business $business, array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'type' => ProductType::Product,
            'name' => 'Product '.Str::random(5),
            'sale_price' => '120.0000',
        ], $attributes));
        $product->business_id = $business->id;
        $product->save();

        return $product;
    }

    private function makeTax(Business $business, array $attributes = []): Tax
    {
        $tax = new Tax(array_merge([
            'name' => 'Tax '.Str::random(5),
            'rate' => '20.0000',
        ], $attributes));
        $tax->business_id = $business->id;
        $tax->save();

        return $tax;
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

    private function enableSales(Business $business): void
    {
        $this->rebuildContext();
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => 'sales'],
            ['enabled' => true],
        );
    }

    /**
     * A validated, well-formed store payload - snapshot qty + unit_price so
     * the fixture never depends on catalog state.
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
            'notes' => 'Payment fixture invoice notes.',
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

    private function storeInvoice(User $user, Business $business, Customer $customer): Invoice
    {
        $this->actIn($user, $business);

        $this->post('/invoices', $this->payload($customer))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('invoices.created'))
            ->assertRedirect();

        $invoice = Invoice::query()->latest('id')->first();
        $this->assertNotNull($invoice);

        $invoice->status = InvoiceStatus::Sent;
        $invoice->save();

        return $invoice;
    }

    /**
     * @return array{business: Business, membership: BusinessMembership}
     */
    private function provision(User $user, string $businessName): array
    {
        $business = $this->makeBusiness($businessName);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();

        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['admin']);

        return [$business, $membership];
    }

    public function test_payment_schema_is_in_place(): void
    {
        $this->assertTrue(Schema::hasTable('payments'));
        $this->assertTrue(Schema::hasTable('payment_allocations'));

        foreach ([
            'id', 'business_id', 'payment_number', 'paymentable_type', 'paymentable_id',
            'payment_date', 'amount', 'payment_method', 'reference', 'notes',
            'reversed_at', 'reversed_by', 'reversal_reason', 'created_by',
            'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('payments', $column), "Expected payments.$column to exist.");
        }

        foreach ([
            'id', 'payment_id', 'invoice_id', 'amount', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('payment_allocations', $column), "Expected payment_allocations.$column to exist.");
        }

        $this->assertTrue(Schema::hasColumn('invoices', 'amount_paid'));
        $this->assertTrue(Schema::hasColumn('invoices', 'amount_due'));

        $this->assertTrue(Schema::hasColumns('payments', ['business_id', 'payment_number']));
    }

    public function test_draft_invoice_cannot_receive_payment(): void
    {
        $user = $this->makeUser('Draft Biller');
        [$business] = $this->provision($user, 'Pay Draft Co');
        $this->enableSales($business);
        $customer = $this->makeCustomer($business);
        $this->actIn($user, $business);

        $this->post('/invoices', $this->payload($customer))
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $invoice = Invoice::query()->latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertSame('1200.0000', $invoice->total);

        $this->post('/payments', [
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total,
            'payment_date' => '2026-09-12',
        ])->assertSessionHasErrors();

        $this->assertDatabaseMissing('payments', ['paymentable_type' => 'invoice', 'paymentable_id' => $invoice->id]);
        $this->assertDatabaseMissing('payment_allocations', ['invoice_id' => $invoice->id]);
    }

    public function test_overpayment_is_rejected(): void
    {
        $user = $this->makeUser('Over Biller');
        [$business] = $this->provision($user, 'Pay Over Co');
        $this->enableSales($business);
        $invoice = $this->storeInvoice($user, $business, $this->makeCustomer($business));
        $this->assertSame('1200.0000', $invoice->total);

        $this->post('/payments', [
            'invoice_id' => $invoice->id,
            'amount' => '1300.0000',
            'payment_date' => '2026-09-12',
        ])->assertSessionHasErrors();

        $this->assertDatabaseMissing('payments', ['paymentable_type' => 'invoice', 'paymentable_id' => $invoice->id]);
        $invoice->refresh();
        $this->assertSame('0.0000', $invoice->amount_paid);
        $this->assertSame('1200.0000', $invoice->amount_due);
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
    }

    public function test_newly_created_sent_invoice_starts_payable_with_correct_caches(): void
    {
        $user = $this->makeUser('Fresh Biller');
        [$business] = $this->provision($user, 'Pay Fresh Co');
        $this->enableSales($business);
        $customer = $this->makeCustomer($business);
        $this->actIn($user, $business);

        // A brand-new SENT invoice (no manual reconcile, no prior payment) must
        // carry the synchronized balance caches immediately: paid 0, due=total.
        $this->post('/invoices', $this->payload($customer, ['status' => 'sent']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('invoices.created'))
            ->assertRedirect();

        $invoice = Invoice::query()->latest('id')->first();
        $this->assertNotNull($invoice);
        $this->assertSame(InvoiceStatus::Sent, $invoice->status);
        $this->assertSame('1200.0000', $invoice->total);
        $this->assertSame('0.0000', $invoice->amount_paid);
        $this->assertSame('1200.0000', $invoice->amount_due);

        // And it is immediately payable: a partial payment is accepted without
        // any first-payment trigger.
        $this->post('/payments', [
            'invoice_id' => $invoice->id,
            'amount' => '500.0000',
            'payment_date' => '2026-09-12',
            'payment_method' => 'bank_transfer',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $invoice->refresh();
        $this->assertSame('500.0000', $invoice->amount_paid);
        $this->assertSame('700.0000', $invoice->amount_due);
        $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
    }

    public function test_exact_settlement_marks_invoice_paid(): void
    {
        $user = $this->makeUser('Exact Biller');
        [$business] = $this->provision($user, 'Pay Exact Co');
        $this->enableSales($business);

        $invoice = $this->storeInvoice($user, $business, $this->makeCustomer($business));
        $this->assertNotNull($invoice);

        $this->post('/payments', [
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total,
            'payment_date' => '2026-09-12',
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $payment = Payment::query()->latest('id')->first();
        $this->assertNotNull($payment);
        $this->assertSame('PAY-000001', $payment->payment_number);
        $this->assertDatabaseHas('payment_allocations', ['payment_id' => $payment->id, 'invoice_id' => $invoice->id]);

        $invoice->refresh();
        $this->assertSame('1200.0000', $invoice->amount_paid);
        $this->assertSame('0.0000', $invoice->amount_due);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
    }

    public function test_reversal_restores_balance_and_keeps_number(): void
    {
        $user = $this->makeUser('Reverse Biller');
        [$business] = $this->provision($user, 'Pay Reverse Co');
        $this->enableSales($business);

        $invoice = $this->storeInvoice($user, $business, $this->makeCustomer($business));

        $this->post('/payments', [
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total,
            'payment_date' => '2026-09-12',
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $payment = Payment::query()->latest('id')->first();
        $this->assertNotNull($payment);
        $number = $payment->payment_number;

        $this->post("/payments/{$payment->id}/reverse", ['reason' => 'Customer requested refund'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $payment->refresh();
        $this->assertNotNull($payment->reversed_at);
        $this->assertSame($number, $payment->payment_number);

        $invoice->refresh();
        $this->assertSame('0.0000', $invoice->amount_paid);
        $this->assertSame('1200.0000', $invoice->amount_due);
        $this->assertNotSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNull($invoice->paid_at);
    }

    public function test_double_reversal_is_rejected(): void
    {
        $user = $this->makeUser('Twice Biller');
        [$business] = $this->provision($user, 'Pay Twice Co');
        $this->enableSales($business);

        $invoice = $this->storeInvoice($user, $business, $this->makeCustomer($business));

        $this->post('/payments', [
            'invoice_id' => $invoice->id,
            'amount' => $invoice->total,
            'payment_date' => '2026-09-12',
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $payment = Payment::query()->latest('id')->first();
        $this->assertNotNull($payment);

        $this->post("/payments/{$payment->id}/reverse", ['reason' => 'First reversal'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->post("/payments/{$payment->id}/reverse", ['reason' => 'Second reversal'])
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('payments', ['id' => $payment->id]);
    }
}
