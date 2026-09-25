<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\BusinessSettings;
use App\Services\DocumentService;
use App\Services\DocumentThemeService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PdfDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
        Storage::fake('public');
    }

    /**
     * @return array{0: Business, 1: User}
     */
    private function provision(string $name = 'PDF Co', string $role = 'owner'): array
    {
        $user = User::create([
            'name' => $name.' User',
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('password'),
        ]);

        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();

        foreach (['sales', 'customers'] as $module) {
            BusinessModule::updateOrCreate(
                ['business_id' => $business->id, 'module_key' => $module],
                ['enabled' => true],
            );
        }

        $membership = $user->memberships()->create(['business_id' => $business->id]);

        if (isset($roles[$role])) {
            $membership->assignRole($roles[$role]);
        }

        return [$business, $user];
    }

    private function enter(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();
    }

    private function customer(Business $business, string $name = 'PDF Customer'): Customer
    {
        $customer = new Customer([
            'name' => $name,
            'company_name' => $name.' LLC',
            'email' => 'customer@example.test',
            'phone' => '+93 700 000 000',
            'address' => 'Kabul',
            'opening_balance' => '10.0000',
            'opening_balance_date' => '2026-09-01',
        ]);
        $customer->business_id = $business->id;
        $customer->save();

        return $customer;
    }

    private function invoice(Business $business, User $user, Customer $customer, string $number = 'INV-PDF-001'): Invoice
    {
        $invoice = new Invoice([
            'customer_id' => $customer->id,
            'date' => '2026-09-15',
            'status' => InvoiceStatus::Sent,
            'discount_type' => null,
            'discount_amount' => '0.0000',
            'notes' => 'PDF invoice note',
            'currency_code' => 'USD',
            'exchange_rate' => '70.00000000',
            'base_amount' => '7000.0000',
        ]);
        $invoice->business_id = $business->id;
        $invoice->invoice_number = $number;
        $invoice->subtotal = '100.0000';
        $invoice->tax_amount = '0.0000';
        $invoice->total = '100.0000';
        $invoice->amount_paid = '0.0000';
        $invoice->amount_due = '100.0000';
        $invoice->created_by = $user->id;
        $invoice->save();

        $item = new InvoiceItem([
            'product_id' => null,
            'description' => 'PDF consulting',
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'tax_id' => null,
            'tax_rate' => null,
            'line_subtotal' => '100.0000',
            'line_tax' => '0.0000',
            'line_total' => '100.0000',
            'sort_order' => 0,
        ]);
        $item->invoice_id = $invoice->id;
        $item->save();

        return $invoice;
    }

    private function quotation(Business $business, User $user, Customer $customer): Quotation
    {
        $quotation = new Quotation([
            'customer_id' => $customer->id,
            'date' => '2026-09-16',
            'expiry_date' => '2026-10-16',
            'status' => QuotationStatus::Sent,
            'discount_type' => null,
            'discount_amount' => '0.0000',
            'notes' => 'PDF quotation note',
            'terms' => 'Quotation terms',
            'currency_code' => 'USD',
            'exchange_rate' => '70.00000000',
            'base_amount' => '3500.0000',
        ]);
        $quotation->business_id = $business->id;
        $quotation->quotation_number = 'QUO-PDF-001';
        $quotation->subtotal = '50.0000';
        $quotation->tax_amount = '0.0000';
        $quotation->total = '50.0000';
        $quotation->created_by = $user->id;
        $quotation->save();

        $item = new QuotationItem([
            'product_id' => null,
            'description' => 'PDF proposal',
            'quantity' => '1.0000',
            'unit_price' => '50.0000',
            'tax_id' => null,
            'tax_rate' => null,
            'line_subtotal' => '50.0000',
            'line_tax' => '0.0000',
            'line_total' => '50.0000',
            'sort_order' => 0,
        ]);
        $item->quotation_id = $quotation->id;
        $item->save();

        return $quotation;
    }

    private function assertPdfResponse($response, string $disposition): void
    {
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString($disposition, (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF-', (string) $response->getContent());
        $this->assertGreaterThan(1000, strlen((string) $response->getContent()));
    }

    public function test_invoice_print_and_pdf_share_selected_theme_and_multicurrency_read_model(): void
    {
        [$business, $user] = $this->provision();
        $this->enter($user, $business);

        app(BusinessSettings::class)->updateMany([
            'document.invoice_theme' => 'minimal',
            'document.header_text' => 'Shared PDF header',
        ]);
        $this->app->forgetScopedInstances();

        $customer = $this->customer($business);
        $invoice = $this->invoice($business, $user, $customer);

        $this->get(route('invoices.print', $invoice))
            ->assertOk()
            ->assertViewIs('documents.invoices.minimal')
            ->assertSee('Shared PDF header')
            ->assertSee('USD')
            ->assertSee('AFN')
            ->assertSee('70.0000');

        $this->assertPdfResponse(
            $this->get(route('invoices.pdf', $invoice)),
            'inline',
        );

        $this->assertPdfResponse(
            $this->get(route('invoices.pdf', ['invoice' => $invoice, 'download' => 1])),
            'attachment',
        );
    }

    public function test_quotation_print_and_pdf_use_the_shared_theme_registry(): void
    {
        [$business, $user] = $this->provision();
        $this->enter($user, $business);

        $customer = $this->customer($business);
        $quotation = $this->quotation($business, $user, $customer);

        $this->get(route('quotations.print', $quotation))
            ->assertOk()
            ->assertViewIs('documents.quotations.modern')
            ->assertSee('QUO-PDF-001')
            ->assertSee('Quotation terms');

        $this->assertPdfResponse(
            $this->get(route('quotations.pdf', $quotation)),
            'inline',
        );
    }

    public function test_statement_print_and_pdf_share_filters_balances_and_theme(): void
    {
        [$business, $user] = $this->provision();
        $this->enter($user, $business);

        $customer = $this->customer($business);
        $this->invoice($business, $user, $customer);

        $query = [
            'customer' => $customer,
            'date_from' => '2026-09-10',
            'date_to' => '2026-09-30',
        ];

        $this->get(route('customers.statement', $query))
            ->assertOk()
            ->assertViewIs('documents.statements.modern')
            ->assertViewHas('dateFrom', '2026-09-10')
            ->assertViewHas('dateTo', '2026-09-30')
            ->assertSee('INV-PDF-001')
            ->assertSee('2026-09-10')
            ->assertSee('2026-09-30');

        $document = app(DocumentService::class)->statement(
            $customer,
            ['date_from' => '2026-09-10', 'date_to' => '2026-09-30'],
            forPdf: true,
        );

        $this->assertSame('documents.statements.modern', $document['view']);
        $this->assertSame('2026-09-10', $document['data']['dateFrom']);
        $this->assertSame('2026-09-30', $document['data']['dateTo']);
        $this->assertSame('7010.0000', $document['data']['closingBalance']);

        $this->assertPdfResponse(
            $this->get(route('customers.statement.pdf', $query)),
            'inline',
        );
    }

    public function test_pdf_presentation_embeds_local_logo_without_remote_fetching(): void
    {
        [$business, $user] = $this->provision();
        $this->enter($user, $business);

        $logo = UploadedFile::fake()->image('logo.png', 120, 60);
        $path = $logo->store('business-documents/'.$business->id, 'public');

        app(BusinessSettings::class)->set('document.logo_path', $path);
        $this->app->forgetScopedInstances();

        $presentation = app(DocumentThemeService::class)->presentation(forPdf: true);

        $this->assertIsString($presentation['logo_url']);
        $this->assertStringContainsString($path, $presentation['logo_url']);
        $this->assertIsString($presentation['logo_data_uri']);
        $this->assertStringStartsWith('data:image/png;base64,', $presentation['logo_data_uri']);
        $this->assertStringNotContainsString('http://', $presentation['logo_data_uri']);
        $this->assertStringNotContainsString('https://', $presentation['logo_data_uri']);
    }

    public function test_new_pdf_routes_preserve_module_permission_and_tenant_guards(): void
    {
        $this->get('/invoices/1/pdf')->assertRedirect(route('login'));

        [$businessA, $userA] = $this->provision('PDF A');
        [$businessB, $userB] = $this->provision('PDF B');

        $this->enter($userA, $businessA);
        $customerA = $this->customer($businessA, 'Customer A');
        $invoiceA = $this->invoice($businessA, $userA, $customerA);
        $quotationA = $this->quotation($businessA, $userA, $customerA);

        $this->get(route('invoices.pdf', $invoiceA))->assertOk();
        $this->get(route('quotations.pdf', $quotationA))->assertOk();
        $this->get(route('customers.statement.pdf', $customerA))->assertOk();

        $businessA->modules()->where('module_key', 'sales')->update(['enabled' => false]);
        $this->get(route('invoices.pdf', $invoiceA))->assertForbidden();
        $this->get(route('quotations.pdf', $quotationA))->assertForbidden();
        $businessA->modules()->where('module_key', 'sales')->update(['enabled' => true]);

        $this->enter($userB, $businessB);
        $this->get('/invoices/'.$invoiceA->id.'/pdf')->assertNotFound();
        $this->get('/quotations/'.$quotationA->id.'/pdf')->assertNotFound();
        $this->get('/customers/'.$customerA->id.'/statement/pdf')->assertNotFound();

        [$noRoleBusiness, $noRoleUser] = $this->provision('No PDF Role', 'missing');
        $this->enter($noRoleUser, $noRoleBusiness);
        $customer = $this->customer($noRoleBusiness, 'No Role Customer');
        $invoice = $this->invoice($noRoleBusiness, $noRoleUser, $customer, 'INV-NO-PDF-PERM');

        $this->get('/invoices/'.$invoice->id.'/pdf')->assertForbidden();
        $this->get('/customers/'.$customer->id.'/statement/pdf')->assertForbidden();
    }
}
