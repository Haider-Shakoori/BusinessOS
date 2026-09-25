<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\BusinessSettings;
use App\Services\DocumentThemeService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentThemeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
        Storage::fake('public');
    }

    private function user(string $name = 'Document User'): User
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
    private function provision(string $name = 'Document Co', string $role = 'owner'): array
    {
        $user = $this->user();
        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();

        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => 'sales'],
            ['enabled' => true],
        );

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

    private function invoice(Business $business, User $user, string $number = 'INV-THEME-001'): Invoice
    {
        $customer = new Customer([
            'name' => 'Theme Customer',
            'company_name' => 'Theme Customer LLC',
            'email' => 'customer@example.test',
            'phone' => '+93 700 000 000',
            'address' => 'Kabul',
            'opening_balance' => '0.0000',
        ]);
        $customer->business_id = $business->id;
        $customer->save();

        $invoice = new Invoice([
            'customer_id' => $customer->id,
            'date' => '2026-09-25',
            'status' => InvoiceStatus::Sent,
            'discount_type' => 'fixed',
            'discount_amount' => '10.0000',
            'notes' => 'Invoice notes',
            'currency_code' => 'AFN',
            'exchange_rate' => '1.00000000',
            'base_amount' => '100.0000',
        ]);
        $invoice->business_id = $business->id;
        $invoice->invoice_number = $number;
        $invoice->subtotal = '100.0000';
        $invoice->tax_amount = '10.0000';
        $invoice->total = '100.0000';
        $invoice->amount_paid = '25.0000';
        $invoice->amount_due = '75.0000';
        $invoice->created_by = $user->id;
        $invoice->save();

        $item = new InvoiceItem([
            'product_id' => null,
            'description' => 'Consulting',
            'quantity' => '1.0000',
            'unit_price' => '100.0000',
            'tax_id' => null,
            'tax_rate' => '10.0000',
            'line_subtotal' => '100.0000',
            'line_tax' => '10.0000',
            'line_total' => '110.0000',
            'sort_order' => 0,
        ]);
        $item->invoice_id = $invoice->id;
        $item->save();

        return $invoice;
    }

    public function test_theme_registry_contains_only_static_code_defined_invoice_views(): void
    {
        $themes = config('document_themes.documents.invoice.themes');

        $this->assertSame(['modern', 'minimal'], array_keys($themes));
        $this->assertSame('documents.invoices.modern', $themes['modern']['view']);
        $this->assertSame('documents.invoices.minimal', $themes['minimal']['view']);
        $this->assertSame('modern', config('document_themes.documents.invoice.default'));
        $this->assertSame('modern', config('settings.definitions.document.invoice_theme.default'));
    }

    public function test_document_settings_persist_and_theme_switches_invoice_rendering(): void
    {
        [$business, $user] = $this->provision();
        $this->enter($user, $business);
        $invoice = $this->invoice($business, $user);

        $this->get(route('invoices.print', $invoice))
            ->assertOk()
            ->assertViewIs('documents.invoices.modern')
            ->assertSee('INV-THEME-001');

        $this->patch('/settings', [
            'document.invoice_theme' => 'minimal',
            'document.accent_color' => '#123ABC',
            'document.header_text' => 'Header message',
            'document.footer_text' => 'Footer message',
            'document.terms' => 'Pay within 30 days.',
            'document.bank_details' => 'Bank: Example',
            'document.signature_line' => 'Finance Manager',
        ])->assertRedirect(route('settings.index'));

        $this->app->forgetScopedInstances();

        $this->assertDatabaseHas('settings', [
            'business_id' => $business->id,
            'group' => 'document',
            'key' => 'invoice_theme',
            'value' => 'minimal',
        ]);
        $this->assertDatabaseHas('settings', [
            'business_id' => $business->id,
            'group' => 'document',
            'key' => 'accent_color',
            'value' => '#123ABC',
        ]);

        $this->get(route('invoices.print', $invoice))
            ->assertOk()
            ->assertViewIs('documents.invoices.minimal')
            ->assertViewHas('presentation', fn (array $presentation): bool =>
                $presentation['accent_color'] === '#123ABC'
                && $presentation['header_text'] === 'Header message'
                && $presentation['terms'] === 'Pay within 30 days.'
                && $presentation['bank_details'] === 'Bank: Example'
                && $presentation['signature_line'] === 'Finance Manager'
            )
            ->assertSee('Header message')
            ->assertSee('Footer message')
            ->assertSee('Pay within 30 days.')
            ->assertSee('Bank: Example')
            ->assertSee('Finance Manager');
    }

    public function test_tampered_theme_value_falls_back_to_registered_default_and_never_executes_a_view_path(): void
    {
        [$business, $user] = $this->provision();
        $this->enter($user, $business);
        $invoice = $this->invoice($business, $user);

        Setting::updateOrCreate(
            ['business_id' => $business->id, 'group' => 'document', 'key' => 'invoice_theme'],
            ['value' => '../../evil-template', 'type' => 'string'],
        );
        $this->app->forgetScopedInstances();

        $resolved = app(DocumentThemeService::class)->resolve('invoice');
        $this->assertSame('modern', $resolved['key']);
        $this->assertSame('documents.invoices.modern', $resolved['view']);

        $this->get(route('invoices.print', $invoice))
            ->assertOk()
            ->assertViewIs('documents.invoices.modern');
    }

    public function test_custom_document_text_is_escaped_not_executed(): void
    {
        [$business, $user] = $this->provision();
        $this->enter($user, $business);
        $invoice = $this->invoice($business, $user);

        app(BusinessSettings::class)->updateMany([
            'document.header_text' => '<script>alert(1)</script>',
            'document.terms' => '<img src=x onerror=alert(2)>',
        ]);
        $this->app->forgetScopedInstances();

        $this->get(route('invoices.print', $invoice))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;img src=x onerror=alert(2)&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false);
    }

    public function test_logo_upload_replace_and_remove_are_business_scoped(): void
    {
        [$businessA, $userA] = $this->provision('Logo A');
        [$businessB, $userB] = $this->provision('Logo B');

        $this->enter($userA, $businessA);

        $first = UploadedFile::fake()->image('first.png', 160, 80)->size(100);
        $this->post('/settings', [
            '_method' => 'PATCH',
            'document.logo' => $first,
        ])->assertRedirect(route('settings.index'));

        $this->app->forgetScopedInstances();
        $firstPath = (string) app(BusinessSettings::class)->get('document.logo_path');
        $this->assertStringStartsWith('business-documents/'.$businessA->id.'/', $firstPath);
        Storage::disk('public')->assertExists($firstPath);

        $second = UploadedFile::fake()->image('second.jpg', 180, 90)->size(100);
        $this->post('/settings', [
            '_method' => 'PATCH',
            'document.logo' => $second,
        ])->assertRedirect(route('settings.index'));

        $this->app->forgetScopedInstances();
        $secondPath = (string) app(BusinessSettings::class)->get('document.logo_path');
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $this->enter($userB, $businessB);
        $this->assertNull(app(BusinessSettings::class)->get('document.logo_path'));
        $this->get('/settings')->assertOk()->assertDontSee(Storage::disk('public')->url($secondPath));

        $this->enter($userA, $businessA);
        $this->post('/settings', [
            '_method' => 'PATCH',
            'document.remove_logo' => '1',
        ])->assertRedirect(route('settings.index'));

        $this->app->forgetScopedInstances();
        $this->assertNull(app(BusinessSettings::class)->get('document.logo_path'));
        Storage::disk('public')->assertMissing($secondPath);
    }

    public function test_logo_validation_rejects_svg_and_invalid_theme_or_accent(): void
    {
        [$business, $user] = $this->provision();
        $this->enter($user, $business);

        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->post('/settings', [
            '_method' => 'PATCH',
            'document.logo' => $svg,
        ])->assertSessionHasErrors('document.logo');

        $this->patch('/settings', ['document.invoice_theme' => '../../evil'])
            ->assertSessionHasErrors('document.invoice_theme');

        $this->patch('/settings', ['document.accent_color' => 'red'])
            ->assertSessionHasErrors('document.accent_color');

        $this->assertDatabaseMissing('settings', [
            'business_id' => $business->id,
            'group' => 'document',
            'key' => 'logo_path',
        ]);
    }

    public function test_invoice_print_route_is_permission_module_and_tenant_guarded(): void
    {
        $this->get('/invoices/1/print')->assertRedirect(route('login'));

        [$businessA, $userA] = $this->provision('Print A');
        [$businessB, $userB] = $this->provision('Print B');

        $this->enter($userA, $businessA);
        $invoice = $this->invoice($businessA, $userA);

        $this->get(route('invoices.print', $invoice))->assertOk();

        $businessA->modules()->where('module_key', 'sales')->update(['enabled' => false]);
        $this->get(route('invoices.print', $invoice))->assertForbidden();
        $businessA->modules()->where('module_key', 'sales')->update(['enabled' => true]);

        $this->enter($userB, $businessB);
        $this->get(route('invoices.print', $invoice))->assertNotFound();

        [$noRoleBusiness, $noRoleUser] = $this->provision('No Permission Print', 'missing');
        $this->enter($noRoleUser, $noRoleBusiness);

        $ownInvoice = $this->invoice($noRoleBusiness, $noRoleUser, 'INV-NO-PERM');
        $this->get('/invoices/'.$ownInvoice->id.'/print')->assertForbidden();
    }
}
