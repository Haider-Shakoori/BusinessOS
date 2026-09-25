<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        CarbonImmutable::setTestNow('2026-09-25 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeUser(string $name = 'Dashboard User'): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    /**
     * @return array{0: Business, 1: User}
     */
    private function provision(string $name = 'Dashboard Co', string $role = 'owner'): array
    {
        $user = $this->makeUser();
        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();

        $membership = $user->memberships()->create(['business_id' => $business->id]);

        if (isset($roles[$role])) {
            $membership->assignRole($roles[$role]);
        }

        return [$business, $user];
    }

    private function enable(Business $business, string ...$modules): void
    {
        foreach ($modules as $module) {
            BusinessModule::updateOrCreate(
                ['business_id' => $business->id, 'module_key' => $module],
                ['enabled' => true],
            );
        }
    }

    private function enter(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();
    }

    private function customer(Business $business, string $name = 'Acme Customer'): Customer
    {
        $customer = new Customer([
            'name' => $name,
            'company_name' => $name.' LLC',
            'email' => Str::random(8).'@example.test',
        ]);
        $customer->business_id = $business->id;
        $customer->save();

        return $customer;
    }

    private function invoice(
        Business $business,
        User $user,
        Customer $customer,
        string $number,
        string $date,
        string $total,
        string $due,
        InvoiceStatus $status = InvoiceStatus::Sent,
    ): Invoice {
        $invoice = new Invoice;
        $invoice->business_id = $business->id;
        $invoice->invoice_number = $number;
        $invoice->customer_id = $customer->id;
        $invoice->date = $date;
        $invoice->status = $status;
        $invoice->subtotal = $total;
        $invoice->discount_amount = '0.0000';
        $invoice->tax_amount = '0.0000';
        $invoice->total = $total;
        $invoice->amount_paid = bcsub($total, $due, 4);
        $invoice->amount_due = $due;
        $invoice->currency_code = 'AFN';
        $invoice->exchange_rate = '1.00000000';
        $invoice->base_amount = $total;
        $invoice->created_by = $user->id;
        $invoice->save();

        return $invoice;
    }

    private function payment(
        Business $business,
        User $user,
        Customer $customer,
        string $number,
        string $date,
        string $amount,
        bool $reversed = false,
    ): Payment {
        $payment = new Payment;
        $payment->business_id = $business->id;
        $payment->payment_number = $number;
        $payment->paymentable_type = 'invoice';
        $payment->paymentable_id = 1;
        $payment->party_type = 'customer';
        $payment->party_id = $customer->id;
        $payment->payment_date = $date;
        $payment->amount = $amount;
        $payment->payment_method = PaymentMethod::Cash;
        $payment->currency_code = 'AFN';
        $payment->exchange_rate = '1.00000000';
        $payment->base_amount = $amount;
        $payment->created_by = $user->id;

        if ($reversed) {
            $payment->reversed_at = $date.' 12:00:00';
            $payment->reversed_by = $user->id;
            $payment->reversal_reason = 'Dashboard fixture reversal';
        }

        $payment->save();

        return $payment;
    }

    private function expense(
        Business $business,
        User $user,
        string $number,
        string $date,
        string $amount,
    ): Expense {
        $expense = new Expense;
        $expense->business_id = $business->id;
        $expense->expense_number = $number;
        $expense->expense_date = $date;
        $expense->amount = $amount;
        $expense->payment_method = PaymentMethod::Cash;
        $expense->vendor = 'Dashboard Vendor';
        $expense->currency_code = 'AFN';
        $expense->exchange_rate = '1.00000000';
        $expense->base_amount = $amount;
        $expense->created_by = $user->id;
        $expense->save();

        return $expense;
    }

    public function test_dashboard_defaults_to_current_month_and_calculates_exact_financial_widgets(): void
    {
        [$business, $user] = $this->provision();
        $this->enable($business, 'sales', 'expenses', 'customers');
        $customer = $this->customer($business);

        $this->invoice($business, $user, $customer, 'INV-001', '2026-09-05', '1000.0000', '400.0000');
        $this->invoice($business, $user, $customer, 'INV-002', '2026-09-10', '500.0000', '0.0000', InvoiceStatus::Paid);
        $this->invoice($business, $user, $customer, 'INV-DRAFT', '2026-09-11', '999.0000', '999.0000', InvoiceStatus::Draft);
        $this->invoice($business, $user, $customer, 'INV-OLD', '2026-08-20', '200.0000', '200.0000');

        $this->payment($business, $user, $customer, 'PAY-001', '2026-09-12', '300.0000');
        $this->payment($business, $user, $customer, 'PAY-REV', '2026-09-13', '200.0000', true);
        $this->expense($business, $user, 'EXP-001', '2026-09-15', '120.0000');
        $this->expense($business, $user, 'EXP-OLD', '2026-08-15', '50.0000');

        // Foreign-business data must never leak into any dashboard aggregate.
        [$foreignBusiness, $foreignUser] = $this->provision('Foreign Dashboard Co');
        $foreignCustomer = $this->customer($foreignBusiness, 'Foreign Customer');
        $this->invoice($foreignBusiness, $foreignUser, $foreignCustomer, 'INV-FOREIGN', '2026-09-06', '9000.0000', '9000.0000');

        $this->enter($user, $business);

        $this->get('/app')
            ->assertOk()
            ->assertViewHas('dateFrom', '2026-09-01')
            ->assertViewHas('dateTo', '2026-09-25')
            ->assertViewHas('dashboard', function (array $dashboard): bool {
                return $dashboard['metrics']['sales'] === ['amount' => '1500.0000', 'count' => 2]
                    && $dashboard['metrics']['revenue'] === ['amount' => '300.0000', 'count' => 1]
                    && $dashboard['metrics']['expenses'] === ['amount' => '120.0000', 'count' => 1]
                    && $dashboard['metrics']['receivables'] === ['amount' => '400.0000', 'count' => 1]
                    && $dashboard['top_customers']->count() === 1
                    && $dashboard['top_customers']->first()['amount'] === '1500.0000'
                    && $dashboard['top_customers']->first()['invoice_count'] === 2;
            })
            ->assertSee('INV-001')
            ->assertSee('PAY-001')
            ->assertSee('EXP-001')
            ->assertDontSee('INV-FOREIGN');
    }

    public function test_dashboard_custom_date_range_changes_the_aggregates(): void
    {
        [$business, $user] = $this->provision();
        $this->enable($business, 'sales', 'expenses', 'customers');
        $customer = $this->customer($business);

        $this->invoice($business, $user, $customer, 'INV-AUG', '2026-08-20', '200.0000', '200.0000');
        $this->invoice($business, $user, $customer, 'INV-SEP', '2026-09-05', '1000.0000', '400.0000');

        $this->enter($user, $business);

        $this->get('/app?range=custom&date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()
            ->assertViewHas('range', 'custom')
            ->assertViewHas('dashboard', fn (array $dashboard): bool => $dashboard['metrics']['sales']['amount'] === '200.0000'
                && $dashboard['metrics']['sales']['count'] === 1
                && $dashboard['metrics']['receivables']['amount'] === '200.0000'
            );
    }

    public function test_dashboard_hides_widgets_when_modules_are_disabled(): void
    {
        [$business, $user] = $this->provision();
        $this->enter($user, $business);

        $this->get('/app')
            ->assertOk()
            ->assertViewHas('visibility', fn (array $visibility): bool => $visibility['sales'] === false
                && $visibility['revenue'] === false
                && $visibility['expenses'] === false
                && $visibility['receivables'] === false
                && $visibility['top_customers'] === false
            )
            ->assertSee(__('dashboard.no_widgets'));
    }

    public function test_dashboard_never_exposes_widget_data_without_matching_permissions(): void
    {
        [$business, $user] = $this->provision('No Role Dashboard', 'missing');
        $this->enable($business, 'sales', 'expenses', 'customers');

        $customer = $this->customer($business);
        $this->invoice($business, $user, $customer, 'INV-SECRET', '2026-09-05', '9999.0000', '9999.0000');
        $this->expense($business, $user, 'EXP-SECRET', '2026-09-05', '8888.0000');

        $this->enter($user, $business);

        $this->get('/app')
            ->assertOk()
            ->assertViewHas('visibility', fn (array $visibility): bool => ! in_array(true, $visibility, true)
            )
            ->assertSee(__('dashboard.no_widgets'))
            ->assertDontSee('INV-SECRET')
            ->assertDontSee('9999.00')
            ->assertDontSee('EXP-SECRET')
            ->assertDontSee('8888.00');
    }

    public function test_dashboard_presets_resolve_to_expected_ranges(): void
    {
        [$business, $user] = $this->provision();
        $this->enter($user, $business);

        $this->get('/app?range=quarter')
            ->assertOk()
            ->assertViewHas('dateFrom', '2026-07-01')
            ->assertViewHas('dateTo', '2026-09-25');

        $this->get('/app?range=year')
            ->assertOk()
            ->assertViewHas('dateFrom', '2026-01-01')
            ->assertViewHas('dateTo', '2026-09-25');
    }

    public function test_dashboard_rejects_invalid_date_ranges(): void
    {
        [$business, $user] = $this->provision();
        $this->enter($user, $business);

        $this->get('/app?range=custom&date_from=2026-09-20&date_to=2026-09-01')
            ->assertSessionHasErrors('date_to');
    }
}
