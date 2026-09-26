<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReportTest extends TestCase
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

    private function user(string $name = 'Report User'): User
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
    private function provision(string $businessName = 'Report Co', string $role = 'owner'): array
    {
        $user = $this->user();
        $business = Business::create(['name' => $businessName]);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();

        $membership = $user->memberships()->create(['business_id' => $business->id]);

        if (isset($roles[$role])) {
            $membership->assignRole($roles[$role]);
        }

        return [$business, $user];
    }

    private function enableReports(Business $business): void
    {
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => 'reports'],
            ['enabled' => true],
        );
    }

    private function enter(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();
    }

    private function customer(Business $business, string $name): Customer
    {
        $customer = new Customer([
            'name' => $name,
            'company_name' => $name.' LLC',
            'opening_balance' => '0.0000',
        ]);
        $customer->business_id = $business->id;
        $customer->save();

        return $customer;
    }

    private function product(Business $business, string $name, string $sku): Product
    {
        $product = new Product([
            'type' => ProductType::Product,
            'name' => $name,
            'sku' => $sku,
            'sale_price' => '10.0000',
        ]);
        $product->business_id = $business->id;
        $product->save();

        return $product;
    }

    private function category(Business $business, string $name): Category
    {
        $category = new Category(['name' => $name]);
        $category->business_id = $business->id;
        $category->save();

        return $category;
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
        string $tax = '0.0000',
        string $subtotal = '0.0000',
        ?string $discountType = null,
        string $discountAmount = '0.0000',
    ): Invoice {
        $invoice = new Invoice([
            'customer_id' => $customer->id,
            'date' => $date,
            'status' => $status,
            'discount_type' => $discountType,
            'discount_amount' => $discountAmount,
            'currency_code' => 'AFN',
            'exchange_rate' => '1.00000000',
            'base_amount' => $total,
        ]);
        $invoice->business_id = $business->id;
        $invoice->invoice_number = $number;
        $invoice->subtotal = $subtotal === '0.0000' ? $total : $subtotal;
        $invoice->tax_amount = $tax;
        $invoice->total = $total;
        $invoice->amount_paid = bcsub($total, $due, 4);
        $invoice->amount_due = $due;
        $invoice->created_by = $user->id;
        $invoice->save();

        return $invoice;
    }

    private function item(
        Invoice $invoice,
        ?Product $product,
        string $description,
        string $quantity,
        string $lineTotal,
        int $sortOrder,
    ): InvoiceItem {
        $item = new InvoiceItem([
            'product_id' => $product?->id,
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => '10.0000',
            'tax_id' => null,
            'tax_rate' => null,
            'line_subtotal' => $lineTotal,
            'line_tax' => '0.0000',
            'line_total' => $lineTotal,
            'sort_order' => $sortOrder,
        ]);
        $item->invoice_id = $invoice->id;
        $item->save();

        return $item;
    }

    private function expense(
        Business $business,
        User $user,
        string $number,
        string $date,
        string $amount,
        ?Category $category = null,
    ): Expense {
        $expense = new Expense([
            'category_id' => $category?->id,
            'expense_date' => $date,
            'amount' => $amount,
            'payment_method' => PaymentMethod::Cash,
            'currency_code' => 'AFN',
            'exchange_rate' => '1.00000000',
            'base_amount' => $amount,
        ]);
        $expense->business_id = $business->id;
        $expense->expense_number = $number;
        $expense->created_by = $user->id;
        $expense->save();

        return $expense;
    }

    private function csvRows($response): array
    {
        $content = $response->streamedContent();
        $content = str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    public function test_reports_are_guarded_by_auth_module_and_reports_permission(): void
    {
        $this->get('/reports')->assertRedirect(route('login'));

        [$business, $owner] = $this->provision();
        $this->enter($owner, $business);

        $this->get('/reports')->assertForbidden();
        $this->get('/app')->assertOk()->assertSee(__('navigation.reports'));

        $this->enableReports($business);
        $this->get('/reports')->assertOk();
        $this->get('/app')->assertOk()->assertSee(__('navigation.reports'));

        [$noRoleBusiness, $noRoleUser] = $this->provision('No Role Reports', 'missing');
        $this->enableReports($noRoleBusiness);
        $this->enter($noRoleUser, $noRoleBusiness);
        $this->get('/reports')->assertForbidden();
    }

    public function test_sales_summary_excludes_drafts_outside_dates_and_foreign_businesses(): void
    {
        [$business, $user] = $this->provision();
        $this->enableReports($business);
        $customer = $this->customer($business, 'Acme');

        $this->invoice($business, $user, $customer, 'INV-001', '2026-09-05', '100.0000', '60.0000', InvoiceStatus::Sent, '10.0000');
        $this->invoice($business, $user, $customer, 'INV-002', '2026-09-10', '200.0000', '0.0000', InvoiceStatus::Paid, '20.0000');
        $this->invoice($business, $user, $customer, 'INV-DRAFT', '2026-09-11', '500.0000', '500.0000', InvoiceStatus::Draft, '50.0000');
        $this->invoice($business, $user, $customer, 'INV-OLD', '2026-08-20', '50.0000', '50.0000', InvoiceStatus::Sent, '5.0000');

        [$foreignBusiness, $foreignUser] = $this->provision('Foreign Reports Co');
        $foreignCustomer = $this->customer($foreignBusiness, 'Foreign');
        $this->invoice($foreignBusiness, $foreignUser, $foreignCustomer, 'INV-FOREIGN', '2026-09-05', '9000.0000', '9000.0000', InvoiceStatus::Sent, '900.0000');

        $this->enter($user, $business);

        $this->get('/reports?report=summary')
            ->assertOk()
            ->assertViewHas('dateFrom', '2026-09-01')
            ->assertViewHas('dateTo', '2026-09-25')
            ->assertViewHas('reportData', function (array $data): bool {
                return $data['sales'] === '300.0000'
                    && $data['invoice_count'] === 2
                    && $data['average_invoice'] === '150.0000'
                    && $data['tax'] === '30.0000'
                    && $data['receivables'] === '60.0000';
            });

        $this->get('/reports?report=summary&range=custom&date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()
            ->assertViewHas('reportData', function (array $data): bool {
                return $data['sales'] === '50.0000'
                    && $data['invoice_count'] === 1
                    && $data['receivables'] === '50.0000';
            });
    }

    public function test_sales_by_product_allocates_document_discount_and_reconciles_to_invoice_total(): void
    {
        [$business, $user] = $this->provision();
        $this->enableReports($business);
        $customer = $this->customer($business, 'Product Buyer');
        $alpha = $this->product($business, 'Alpha', 'A-1');
        $beta = $this->product($business, 'Beta', 'B-1');

        $invoice = $this->invoice(
            $business,
            $user,
            $customer,
            'INV-PRODUCT',
            '2026-09-05',
            '90.0000',
            '90.0000',
            InvoiceStatus::Sent,
            '0.0000',
            '100.0000',
            'fixed',
            '10.0000',
        );
        $this->item($invoice, $alpha, 'Alpha line', '2.0000', '60.0000', 0);
        $this->item($invoice, $beta, 'Beta line', '1.0000', '40.0000', 1);

        $this->enter($user, $business);

        $this->get('/reports?report=products')
            ->assertOk()
            ->assertViewHas('reportData', function (array $data): bool {
                $rows = $data['rows']->keyBy('name');

                return $data['total'] === '90.0000'
                    && $rows['Alpha']['sales'] === '54.0000'
                    && $rows['Alpha']['quantity'] === '2.0000'
                    && $rows['Alpha']['invoice_count'] === 1
                    && $rows['Beta']['sales'] === '36.0000';
            });
    }

    public function test_customer_expense_and_receivable_reports_use_consistent_base_totals(): void
    {
        [$business, $user] = $this->provision();
        $this->enableReports($business);
        $acme = $this->customer($business, 'Acme');
        $beta = $this->customer($business, 'Beta');

        $this->invoice($business, $user, $acme, 'INV-A1', '2026-09-02', '100.0000', '40.0000');
        $this->invoice($business, $user, $acme, 'INV-A2', '2026-09-03', '200.0000', '0.0000', InvoiceStatus::Paid);
        $this->invoice($business, $user, $beta, 'INV-B1', '2026-09-04', '150.0000', '50.0000', InvoiceStatus::PartiallyPaid);

        $office = $this->category($business, 'Office');
        $travel = $this->category($business, 'Travel');
        $this->expense($business, $user, 'EXP-1', '2026-09-05', '20.0000', $office);
        $this->expense($business, $user, 'EXP-2', '2026-09-06', '30.0000', $office);
        $this->expense($business, $user, 'EXP-3', '2026-09-07', '10.0000', $travel);

        $this->enter($user, $business);

        $this->get('/reports?report=customers')
            ->assertOk()
            ->assertViewHas('reportData', function (array $data): bool {
                $rows = $data['rows']->keyBy('name');

                return $data['total'] === '450.0000'
                    && $rows['Acme']['invoice_count'] === 2
                    && $rows['Acme']['sales'] === '300.0000'
                    && $rows['Acme']['receivables'] === '40.0000'
                    && $rows['Beta']['sales'] === '150.0000'
                    && $rows['Beta']['receivables'] === '50.0000';
            });

        $this->get('/reports?report=expenses')
            ->assertOk()
            ->assertViewHas('reportData', function (array $data): bool {
                $rows = $data['rows']->keyBy('name');

                return $data['total'] === '60.0000'
                    && $rows['Office']['count'] === 2
                    && $rows['Office']['amount'] === '50.0000'
                    && $rows['Travel']['amount'] === '10.0000';
            });

        $this->get('/reports?report=receivables')
            ->assertOk()
            ->assertViewHas('reportData', function (array $data): bool {
                return $data['total'] === '90.0000'
                    && $data['rows']->count() === 2
                    && $data['rows']->pluck('invoice_number')->sort()->values()->all() === ['INV-A1', 'INV-B1'];
            });
    }

    public function test_report_csv_uses_same_filters_and_is_formula_safe(): void
    {
        [$business, $user] = $this->provision();
        $this->enableReports($business);
        $customer = $this->customer($business, '=Formula Customer');
        $this->invoice($business, $user, $customer, 'INV-CSV', '2026-09-15', '120.0000', '20.0000');

        $this->enter($user, $business);

        $response = $this->get('/reports/export/customers?range=custom&date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $rows = $this->csvRows($response);

        $this->assertStringContainsString(
            'attachment; filename=report-customers-',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertSame(
            ['Customer', 'Company', 'Invoice Count', 'Sales', 'Receivables', 'Currency', 'Date From', 'Date To'],
            $rows[0],
        );
        $this->assertSame("'=Formula Customer", $rows[1][0]);
        $this->assertSame('120.0000', $rows[1][3]);
        $this->assertSame('20.0000', $rows[1][4]);
        $this->assertSame('2026-09-01', $rows[1][6]);
        $this->assertSame('2026-09-30', $rows[1][7]);
    }

    public function test_report_validation_rejects_invalid_type_and_date_ranges(): void
    {
        [$business, $user] = $this->provision();
        $this->enableReports($business);
        $this->enter($user, $business);

        $this->get('/reports?report=bogus')->assertSessionHasErrors('report');
        $this->get('/reports?range=custom&date_from=2026-09-20&date_to=2026-09-01')
            ->assertSessionHasErrors('date_to');
        $this->get('/reports/export/bogus')->assertNotFound();
    }
}
