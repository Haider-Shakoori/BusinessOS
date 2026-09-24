<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    private function makeUser(string $name = 'Export User'): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    /**
     * @return array{Business, BusinessMembership, array<string, Role>}
     */
    private function provision(User $user, string $name, string $role = 'owner'): array
    {
        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles[$role]);

        return [$business, $membership, $roles];
    }

    private function enable(Business $business, string $module): void
    {
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => $module],
            ['enabled' => true],
        );
    }

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();
    }

    private function makeCustomer(Business $business, array $attributes = []): Customer
    {
        $customer = new Customer(array_merge([
            'name' => 'Customer '.Str::random(5),
            'opening_balance' => '0.0000',
        ], $attributes));
        $customer->business_id = $business->id;
        $customer->save();

        return $customer;
    }

    private function makeCategory(Business $business, string $name): Category
    {
        $category = new Category(['name' => $name]);
        $category->business_id = $business->id;
        $category->save();

        return $category;
    }

    private function makeProduct(Business $business, array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'type' => ProductType::Product,
            'name' => 'Product '.Str::random(5),
            'sale_price' => '10.0000',
        ], $attributes));
        $product->business_id = $business->id;
        $product->save();

        return $product;
    }

    private function makeInvoice(
        Business $business,
        User $user,
        Customer $customer,
        string $number,
        InvoiceStatus $status = InvoiceStatus::Sent,
    ): Invoice {
        $invoice = new Invoice([
            'customer_id' => $customer->id,
            'date' => '2026-09-20',
            'status' => $status,
            'currency_code' => 'AFN',
            'exchange_rate' => '1.00000000',
            'base_amount' => '100.0000',
            'notes' => 'Invoice note',
        ]);
        $invoice->business_id = $business->id;
        $invoice->invoice_number = $number;
        $invoice->subtotal = '100.0000';
        $invoice->discount_amount = '0.0000';
        $invoice->tax_amount = '0.0000';
        $invoice->total = '100.0000';
        $invoice->amount_paid = '0.0000';
        $invoice->amount_due = '100.0000';
        $invoice->created_by = $user->id;
        $invoice->save();

        return $invoice;
    }

    private function makeExpense(
        Business $business,
        User $user,
        string $number,
        array $attributes = [],
    ): Expense {
        $expense = new Expense(array_merge([
            'expense_date' => '2026-09-20',
            'amount' => '25.0000',
            'payment_method' => PaymentMethod::Cash,
            'currency_code' => 'AFN',
            'exchange_rate' => '1.00000000',
            'base_amount' => '25.0000',
        ], $attributes));
        $expense->business_id = $business->id;
        $expense->expense_number = $number;
        $expense->created_by = $user->id;
        $expense->save();

        return $expense;
    }

    private function csvRows($response): array
    {
        $content = $response->streamedContent();
        $content = str_starts_with($content, "\xEF\xBB\xBF")
            ? substr($content, 3)
            : $content;

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

    public function test_export_routes_are_guarded_by_auth_module_and_read_permission(): void
    {
        $this->get('/customers/export')->assertRedirect('/login');

        $owner = $this->makeUser('Owner');
        [$ownerBusiness] = $this->provision($owner, 'Owner Co.');
        $this->actIn($owner, $ownerBusiness);
        $this->get('/customers/export')->assertForbidden();

        $viewer = $this->makeUser('Viewer');
        [$viewerBusiness] = $this->provision($viewer, 'Viewer Co.', 'viewer');
        $this->enable($viewerBusiness, 'customers');
        $this->enable($viewerBusiness, 'products');
        $this->actIn($viewer, $viewerBusiness);
        $this->get('/customers/export')->assertOk();
        $this->get('/products/export')->assertForbidden();
    }

    public function test_customer_export_is_filtered_tenant_scoped_utf8_and_formula_safe(): void
    {
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'A');
        [$businessB] = $this->provision($user, 'B');
        $this->enable($businessA, 'customers');
        $this->enable($businessB, 'customers');

        $this->makeCustomer($businessA, [
            'name' => '=2+2',
            'company_name' => 'Wanted Company',
            'email' => 'safe@example.test',
        ]);
        $this->makeCustomer($businessA, ['name' => 'Ignored Local']);
        $this->makeCustomer($businessB, ['name' => 'Wanted Foreign', 'company_name' => 'Wanted Company']);

        $this->actIn($user, $businessA);
        $response = $this->get('/customers/export?search=Wanted')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $rows = $this->csvRows($response);
        $this->assertSame(
            ['Name', 'Company', 'Email', 'Phone', 'Address', 'Opening Balance', 'Opening Balance Date', 'Notes'],
            $rows[0],
        );
        $this->assertCount(2, $rows);
        $this->assertSame("'=2+2", $rows[1][0]);
        $this->assertSame('Wanted Company', $rows[1][1]);
        $this->assertStringNotContainsString('Wanted Foreign', $response->streamedContent());
    }

    public function test_product_export_respects_type_search_and_tenant_scope(): void
    {
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'A');
        [$businessB] = $this->provision($user, 'B');
        $this->enable($businessA, 'products');
        $this->enable($businessB, 'products');

        $category = $this->makeCategory($businessA, 'Retail');
        $this->makeProduct($businessA, [
            'name' => 'Target Product',
            'sku' => '+001',
            'category_id' => $category->id,
            'type' => ProductType::Product,
        ]);
        $this->makeProduct($businessA, ['name' => 'Target Service', 'type' => ProductType::Service]);
        $this->makeProduct($businessB, ['name' => 'Target Foreign', 'type' => ProductType::Product]);

        $this->actIn($user, $businessA);
        $rows = $this->csvRows($this->get('/products/export?search=Target&type=product')->assertOk());

        $this->assertCount(2, $rows);
        $this->assertSame('product', $rows[1][0]);
        $this->assertSame('Target Product', $rows[1][1]);
        $this->assertSame("'+001", $rows[1][2]);
        $this->assertSame('Retail', $rows[1][3]);
    }

    public function test_invoice_export_respects_status_search_and_tenant_scope(): void
    {
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'A');
        [$businessB] = $this->provision($user, 'B');
        $this->enable($businessA, 'sales');
        $this->enable($businessB, 'sales');

        $customerA = $this->makeCustomer($businessA, ['name' => 'Acme']);
        $customerB = $this->makeCustomer($businessB, ['name' => 'Acme']);
        $this->makeInvoice($businessA, $user, $customerA, 'INV-LOCAL', InvoiceStatus::Sent);
        $this->makeInvoice($businessA, $user, $customerA, 'INV-DRAFT', InvoiceStatus::Draft);
        $this->makeInvoice($businessB, $user, $customerB, 'INV-FOREIGN', InvoiceStatus::Sent);

        $this->actIn($user, $businessA);
        $rows = $this->csvRows($this->get('/invoices/export?search=Acme&status=sent')->assertOk());

        $this->assertCount(2, $rows);
        $this->assertSame('INV-LOCAL', $rows[1][0]);
        $this->assertSame('sent', $rows[1][3]);
        $this->assertSame('100.0000', $rows[1][10]);
    }

    public function test_expense_export_respects_filters_tenant_scope_and_formula_safety(): void
    {
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'A');
        [$businessB] = $this->provision($user, 'B');
        $this->enable($businessA, 'expenses');
        $this->enable($businessB, 'expenses');

        $category = $this->makeCategory($businessA, 'Travel');
        $this->makeExpense($businessA, $user, 'EXP-LOCAL', [
            'category_id' => $category->id,
            'expense_date' => '2026-09-20',
            'vendor' => '=Danger',
            'reference' => 'REF-1',
        ]);
        $this->makeExpense($businessA, $user, 'EXP-OLD', [
            'category_id' => $category->id,
            'expense_date' => '2026-08-01',
            'vendor' => 'Old',
        ]);
        $this->makeExpense($businessB, $user, 'EXP-FOREIGN', [
            'expense_date' => '2026-09-20',
            'vendor' => '=Danger',
        ]);

        $this->actIn($user, $businessA);
        $query = http_build_query([
            'search' => 'Danger',
            'category_id' => $category->id,
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
        ]);
        $rows = $this->csvRows($this->get('/expenses/export?'.$query)->assertOk());

        $this->assertCount(2, $rows);
        $this->assertSame('EXP-LOCAL', $rows[1][0]);
        $this->assertSame('Travel', $rows[1][2]);
        $this->assertSame("'=Danger", $rows[1][3]);
        $this->assertSame('25.0000', $rows[1][8]);
    }

    public function test_expense_report_export_uses_same_filters_and_download_name(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Report Co.');
        $this->enable($business, 'expenses');
        $category = $this->makeCategory($business, 'Office');
        $this->makeExpense($business, $user, 'EXP-REPORT', ['category_id' => $category->id]);

        $this->actIn($user, $business);
        $response = $this->get('/expenses/report/export?category_id='.$category->id)->assertOk();

        $this->assertStringContainsString(
            'attachment; filename=expense-report-',
            (string) $response->headers->get('Content-Disposition'),
        );
        $this->assertSame('EXP-REPORT', $this->csvRows($response)[1][0]);
    }

    public function test_invalid_export_filters_are_rejected(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Validation Co.');
        foreach (['products', 'sales', 'expenses'] as $module) {
            $this->enable($business, $module);
        }
        $this->actIn($user, $business);

        $this->get('/products/export?type=bogus')->assertSessionHasErrors('type');
        $this->get('/invoices/export?status=bogus')->assertSessionHasErrors('status');
        $this->get('/expenses/export?date_from=2026-09-30&date_to=2026-09-01')
            ->assertSessionHasErrors('date_to');
    }
}
