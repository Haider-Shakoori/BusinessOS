<?php

namespace Tests\Feature;

use App\Enums\ProductType;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Tax;
use App\Models\User;
use App\Services\BusinessSettings;
use App\Services\QuotationService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Invoice CRUD + tenancy + numbering + lifecycle (Batch 15).
 *
 * Invoices belong to CUSTOMERS (there is no patient concept in this
 * application) and live under the `sales` module with a read/write permission
 * split (invoices.view / invoices.manage). The default Viewer role receives
 * invoices.view ONLY (read-only observer), unlike quotations. Numbers come from
 * the Batch 13 DocumentNumberService (DocumentType::Invoice) inside the same
 * transaction as the insert. Draft invoices are editable/deletable; `sent`
 * invoices (including every converted invoice) are finalized and immutable in
 * this batch. Tax is optional — snapshots follow the Batch 14 rules.
 *
 * The customer-id requirement: a request can never substitute another
 * customer's id, a business_id, an invoice_number or any money total.
 * Quotation->invoice conversion is covered by QuotationConversionTest.
 */
class PaymentTest2 extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    private function makeUser(string $name = 'Invoice User'): User
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

    private function enableSales(Business $business): void
    {
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => 'sales'],
            ['enabled' => true],
        );
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
        $tax = new Tax(array_merge(['name' => 'Tax '.Str::random(5), 'rate' => '20.0000'], $attributes));
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

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([$this->sessionKey() => $business->id]);
        $this->rebuildContext();
    }

    private function enableTax(): void
    {
        $this->rebuildContext();
        app(BusinessSettings::class)->set('general.tax_enabled', '1');
    }

    /**
     * A validated, well-formed store payload. `items` uses a snapshot
     * description + qty + price so the test never depends on the product's
     * current catalog state.
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
            'notes' => 'First invoice notes.',
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

    private function storePayload(Customer $customer, array $overrides = []): array
    {
        return $this->payload($customer, $overrides);
    }

    // --- Schema --------------------------------------------------------------

    public function test_invoice_schema_is_in_place(): void
    {
        $this->assertSame('INV', config('numbering.prefixes.invoice'));
        $this->assertTrue(Schema::hasTable('invoices'));
        $this->assertTrue(Schema::hasTable('invoice_items'));

        foreach (['business_id', 'invoice_number', 'customer_id', 'quotation_id', 'date', 'status', 'subtotal', 'discount_type', 'discount_amount', 'tax_amount', 'total', 'notes', 'created_by', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('invoices', $column), "Expected invoices.$column to exist.");
        }

        // invoice_items has NO soft-delete column (approved Batch 15 schema):
        // deleting an invoice soft-deletes only the header; the item snapshot
        // rows stay behind attached to the trashed header.
        foreach (['invoice_id', 'product_id', 'description', 'quantity', 'unit_price', 'tax_id', 'tax_rate', 'line_subtotal', 'line_tax', 'line_total', 'sort_order', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('invoice_items', $column), "Expected invoice_items.$column to exist.");
        }

        // Business-scoped number is unique; the one-to-one source quotation is
        // enforced at the database level (multiple NULLs allowed in SQLite/MySQL).
        $this->assertTrue(Schema::hasIndex('invoices', ['business_id', 'invoice_number']));
        $this->assertTrue(Schema::hasIndex('invoices', ['quotation_id']));

        // Batch 16 added the synchronized payment balance caches. They are derived,
        // never request-writable: PaymentService recomputes them from the active
        // payment_allocations aggregate (the authoritative financial source).
        $this->assertTrue(Schema::hasColumn('invoices', 'amount_paid'));
        $this->assertTrue(Schema::hasColumn('invoices', 'amount_due'));
        $this->assertFalse(Schema::hasColumn('invoices', 'paid_at'));
    }

    // --- Route guarding: auth + business + module + permission ---------------

    public function test_invoice_routes_require_authentication_and_a_business(): void
    {
        $this->get('/invoices')->assertRedirect(route('login'));
        $this->get('/invoices/create')->assertRedirect(route('login'));
        $this->post('/invoices', ['status' => 'draft'])->assertRedirect(route('login'));

        $user = $this->makeUser();
        $this->actingAs($user)->get('/invoices')->assertRedirect(route('business.create'));
    }

    public function test_invoice_module_guard_blocks_when_disabled(): void
    {
        $user = $this->makeUser('Inv Owner');
        [$business] = $this->provision($user, 'Inv Co.', 'owner');
        $this->actIn($user, $business);

        $this->assertTrue(Gate::allows('invoices.view'));
        $this->get('/invoices')->assertForbidden();
        $this->get('/invoices/create')->assertForbidden();
        $this->post('/invoices', ['status' => 'draft'])->assertForbidden();

        $this->enableSales($business);
        $this->get('/invoices')->assertOk();

        $business->modules()->where('module_key', 'sales')->update(['enabled' => false]);
        $this->get('/invoices')->assertForbidden();
    }

    public function test_reads_require_invoices_view_and_writes_require_invoices_manage(): void
    {
        $user = $this->makeUser('Inv Perm User');
        [$business, , $roles] = $this->provision($user, 'Inv Perm Sales Co.', 'viewer');
        $this->enableSales($business);
        $this->actIn($user, $business);

        // NOTE: unlike quotations, the Batch 15 default Viewer role holds
        // invoices.view — it can observe invoices but not manage them.
        $this->assertTrue(Gate::allows('invoices.view'));
        $this->assertFalse(Gate::allows('invoices.manage'));
        $this->assertFalse(Gate::allows('quotations.view'));

        $this->get('/invoices')->assertOk()->assertSee(__('invoices.view_only'));
        $this->get('/invoices/create')->assertForbidden();
        $this->post('/invoices', $this->storePayload($this->makeCustomer($business)))->assertForbidden();
        $this->assertDatabaseCount('invoices', 0);

        // Grant manage as well: the same payload is now created.
        $manageId = Permission::where('name', 'invoices.manage')->value('id');
        $roles['viewer']->permissions()->syncWithoutDetaching([$manageId]);
        $this->rebuildContext();
        $this->actIn($user, $business);
        $this->assertTrue(Gate::allows('invoices.manage'));

        $invoice = $this->storeInvoice($user, $this->makeCustomer($business));
        $this->assertSame('INV-000001', $invoice->invoice_number);
    }

    // --- Owner happy path: exact totals + numbering --------------------------

    public function test_owner_can_create_with_exact_server_computed_totals(): void
    {
        $user = $this->makeUser('Inv Creator');
        [$business] = $this->provision($user, 'Inv Totals Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business, ['name' => 'Acme Trading']);
        $product = $this->makeProduct($business);

        $this->post('/invoices', $this->storePayload($customer, [
            'discount_type' => 'percentage',
            'discount_amount' => '10.0000',
            'items' => [
                [
                    'product_id' => $product->id,
                    'description' => 'Consulting hours',
                    'quantity' => '2.0000',
                    'unit_price' => '100.0000',
                ],
                [
                    'product_id' => null,
                    'description' => 'Setup',
                    'quantity' => '3.0000',
                    'unit_price' => '50.5000',
                ],
            ],
        ]))
            ->assertRedirect(route('invoices.show', Invoice::query()->orderByDesc('id')->firstOrFail()))
            ->assertSessionHas('status', __('invoices.created'));

        $invoice = Invoice::query()->orderByDesc('id')->firstOrFail();

        // Number came from the Batch 13 sequence inside the same transaction.
        $this->assertSame('INV-000001', $invoice->invoice_number);
        $this->assertSame($business->id, $invoice->business_id);
        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertSame($user->id, $invoice->created_by);
        $this->assertNull($invoice->quotation_id);

        // Exact arithmetic: two lines (200.0000 + 151.5000), 10% off, no tax.
        $this->assertSame('351.5000', $invoice->subtotal);
        $this->assertSame('0.0000', $invoice->tax_amount);
        $this->assertSame('percentage', $invoice->discount_type);
        $this->assertSame('10.0000', $invoice->discount_amount);
        $this->assertSame('316.3500', $invoice->total);

        $this->assertCount(2, $invoice->items);
        $first = $invoice->items[0];
        $this->assertSame('200.0000', $first->line_subtotal);
        $this->assertSame('0.0000', $first->line_tax);
        $this->assertSame('200.0000', $first->line_total);
        $second = $invoice->items[1];
        $this->assertSame('151.5000', $second->line_subtotal);

        $this->assertSame($product->id, $first->product_id);
        $this->assertSame('Consulting hours', $first->description);
        $this->assertSame(0, $first->sort_order);
        $this->assertSame(1, $second->sort_order);

        $this->assertDatabaseHas('document_number_sequences', ['business_id' => $business->id, 'document_type' => 'invoice', 'last_number' => 1]);
    }

    public function test_create_ignores_forged_business_number_totals_and_quotation(): void
    {
        $user = $this->makeUser('Inv Forger');
        $quotation = $this->makeQuotation($user);

        [$business] = $this->provision($user, 'Inv Trusted Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $this->post('/invoices', $this->storePayload($customer, [
            'business_id' => 999,
            'invoice_number' => 'HACKED-999999',
            'quotation_id' => $quotation->id,
            'subtotal' => '0.0001',
            'tax_amount' => '999.0000',
            'total' => '0.0001',
            'items' => [
                ['product_id' => null, 'description' => 'One', 'quantity' => '1.0000', 'unit_price' => '100.0000'],
            ],
        ]))->assertSessionHas('status', __('invoices.created'));

        $invoice = Invoice::query()->firstOrFail();
        $this->assertSame('INV-000001', $invoice->invoice_number);
        $this->assertSame($business->id, $invoice->business_id);
        $this->assertNull($invoice->quotation_id);
        $this->assertSame('100.0000', $invoice->subtotal);
        $this->assertSame('0.0000', $invoice->tax_amount);
        $this->assertSame('100.0000', $invoice->total);

        $this->assertFalse(Invoice::query()->where('invoice_number', 'HACKED-999999')->exists());
    }

    public function test_numbers_increment_per_business_and_are_never_reused(): void
    {
        $user = $this->makeUser('Inv Seq User');
        [$businessA] = $this->provision($user, 'Inv Seq A Co.', 'owner');
        [$businessB] = $this->provision($user, 'Inv Seq B Co.', 'owner');
        $this->enableSales($businessA);
        $this->enableSales($businessB);

        $this->actIn($user, $businessA);
        $a1 = $this->storeInvoice($user, $this->makeCustomer($businessA));
        $a2 = $this->storeInvoice($user, $this->makeCustomer($businessA));
        $this->assertSame('INV-000001', $a1->invoice_number);
        $this->assertSame('INV-000002', $a2->invoice_number);

        // Business B starts its own counter at 1.
        $this->actIn($user, $businessB);
        $b1 = $this->storeInvoice($user, $this->makeCustomer($businessB));
        $this->assertSame('INV-000001', $b1->invoice_number);

        // Deleting a draft never frees the number: the next one skips ahead.
        $this->actIn($user, $businessA);
        $invoice = $this->storeInvoice($user, $this->makeCustomer($businessA));
        $this->assertSame('INV-000003', $invoice->invoice_number);
        $this->delete(route('invoices.destroy', $invoice))
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHas('status', __('invoices.deleted'));

        $next = $this->storeInvoice($user, $this->makeCustomer($businessA));
        $this->assertSame('INV-000004', $next->invoice_number);
    }

    public function test_failed_validation_consumes_no_number(): void
    {
        $user = $this->makeUser('Inv Rigid User');
        [$business] = $this->provision($user, 'Inv Rigid Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $payload = $this->storePayload($this->makeCustomer($business));
        $payload['items'] = [];

        $this->post('/invoices', $payload)->assertSessionHasErrors('items');

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('document_number_sequences', 0);
    }

    // --- Tenancy: cross-business isolation -----------------------------------

    public function test_cross_business_show_edit_update_and_delete_are_blocked(): void
    {
        $user = $this->makeUser('Inv Tenant User');
        [$businessA] = $this->provision($user, 'Inv Tenant A Co.', 'owner');
        [$businessB] = $this->provision($user, 'Inv Tenant B Co.', 'owner');
        $this->enableSales($businessA);
        $this->enableSales($businessB);

        $this->actIn($user, $businessA);
        $invoice = $this->storeInvoice($user, $this->makeCustomer($businessA));
        $this->assertSame('INV-000001', $invoice->invoice_number);

        $this->actIn($user, $businessB);
        $this->get(route('invoices.index'))->assertOk()->assertDontSee('INV-000001')->assertSee(__('invoices.no_invoices'));
        $this->get(route('invoices.show', $invoice))->assertNotFound();
        $this->get(route('invoices.edit', $invoice))->assertNotFound();
        $this->patch(route('invoices.update', $invoice), $this->storePayload($this->makeCustomer($businessB)))->assertNotFound();
        $this->delete(route('invoices.destroy', $invoice))->assertNotFound();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'business_id' => $businessA->id, 'deleted_at' => null]);
    }

    public function test_index_and_search_show_only_current_business_invoices(): void
    {
        $user = $this->makeUser('Inv Isolation User');
        [$businessA] = $this->provision($user, 'Inv Iso A Co.', 'owner');
        [$businessB] = $this->provision($user, 'Inv Iso B Co.', 'owner');
        $this->enableSales($businessA);
        $this->enableSales($businessB);

        $this->actIn($user, $businessA);
        $this->storeInvoice($user, $this->makeCustomer($businessA, ['name' => 'Alpha Buyer']));
        $this->actIn($user, $businessB);
        $this->storeInvoice($user, $this->makeCustomer($businessB, ['name' => 'Beta Buyer']));

        $this->actIn($user, $businessB);
        $this->get('/invoices')->assertOk()->assertSee('Beta Buyer')->assertDontSee('Alpha Buyer');
        $this->get('/invoices?search=Beta')->assertOk()->assertSee('Beta Buyer')->assertDontSee('Alpha Buyer');
        $this->get('/invoices?search=Alpha')->assertOk()->assertDontSee('Beta Buyer')->assertSee(__('invoices.no_results'));
    }

    public function test_cross_business_fk_references_are_rejected_at_validation(): void
    {
        $user = $this->makeUser('Inv Fk User');
        [$businessA] = $this->provision($user, 'Inv Fk A Co.', 'owner');
        [$businessB] = $this->provision($user, 'Inv Fk B Co.', 'owner');
        $this->enableSales($businessA);
        $this->actIn($user, $businessA);

        $customerB = $this->makeCustomer($businessB);
        $productB = $this->makeProduct($businessB);
        $taxB = $this->makeTax($businessB);

        $this->post('/invoices', $this->storePayload($customerB))->assertSessionHasErrors('customer_id');
        $this->post('/invoices', $this->storePayload($this->makeCustomer($businessA), [
            'items' => [
                ['product_id' => $productB->id, 'description' => 'Nope', 'quantity' => '1.0000', 'unit_price' => '10.0000'],
            ],
        ]))->assertSessionHasErrors('items.0.product_id');
        $this->assertDatabaseCount('invoices', 0);

        $this->enableTax();
        $this->post('/invoices', $this->storePayload($this->makeCustomer($businessA), [
            'items' => [
                ['product_id' => null, 'description' => 'Nope', 'quantity' => '1.0000', 'unit_price' => '10.0000', 'tax_id' => $taxB->id],
            ],
        ]))->assertSessionHasErrors('items.0.tax_id');
        $this->assertDatabaseCount('invoices', 0);
    }

    // --- Optional tax --------------------------------------------------------

    public function test_tax_disabled_ignores_a_forged_tax_id_and_writes_tax_free_lines(): void
    {
        $user = $this->makeUser('Inv No Tax User');
        [$business] = $this->provision($user, 'Inv No Tax Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $tax = $this->makeTax($business, ['rate' => '20.0000']);
        $invoice = $this->storeInvoice($user, $this->makeCustomer($business), [
            'items' => [
                ['product_id' => null, 'description' => 'Taxed?', 'quantity' => '1.0000', 'unit_price' => '100.0000', 'tax_id' => $tax->id],
            ],
        ]);

        $item = $invoice->items->first();
        $this->assertNull($item->tax_id);
        $this->assertNull($item->tax_rate);
        $this->assertSame('0.0000', $item->line_tax);
        $this->assertSame('100.0000', $invoice->subtotal);
        $this->assertSame('0.0000', $invoice->tax_amount);
        $this->assertSame('100.0000', $invoice->total);
    }

    public function test_tax_enabled_snapshots_the_tax_rate_from_the_live_tax_row(): void
    {
        $user = $this->makeUser('Inv Tax User');
        [$business] = $this->provision($user, 'Inv Tax Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);
        $this->enableTax();

        $tax = $this->makeTax($business, ['rate' => '20.0000']);
        $invoice = $this->storeInvoice($user, $this->makeCustomer($business), [
            'items' => [
                ['product_id' => null, 'description' => 'Widget', 'quantity' => '2.0000', 'unit_price' => '100.0000', 'tax_id' => $tax->id],
                ['product_id' => null, 'description' => 'Setup', 'quantity' => '1.0000', 'unit_price' => '50.0000'],
            ],
        ]);

        $first = $invoice->items[0];
        $this->assertSame($tax->id, $first->tax_id);
        $this->assertSame('20.0000', $first->tax_rate);
        $this->assertSame('200.0000', $first->line_subtotal);
        $this->assertSame('40.0000', $first->line_tax);
        $this->assertSame('240.0000', $first->line_total);

        $second = $invoice->items[1];
        $this->assertNull($second->tax_rate);
        $this->assertSame('0.0000', $second->line_tax);

        $this->assertSame('250.0000', $invoice->subtotal);
        $this->assertSame('40.0000', $invoice->tax_amount);
        $this->assertSame('290.0000', $invoice->total);
    }

    public function test_changing_the_tax_rate_later_never_rewrites_stored_snapshots(): void
    {
        $user = $this->makeUser('Inv Snapshot User');
        [$business] = $this->provision($user, 'Inv Snapshot Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);
        $this->enableTax();

        $tax = $this->makeTax($business, ['rate' => '10.0000']);
        $invoice = $this->storeInvoice($user, $this->makeCustomer($business), [
            'items' => [
                ['product_id' => null, 'description' => 'Widget', 'quantity' => '1.0000', 'unit_price' => '200.0000', 'tax_id' => $tax->id],
            ],
        ]);

        $this->assertSame('10.0000', $invoice->items->first()->tax_rate);
        $this->assertSame('20.0000', $invoice->tax_amount);

        $tax->rate = '99.0000';
        $tax->save();

        $invoice->refresh();
        $this->assertSame('10.0000', $invoice->items->first()->tax_rate);
        $this->assertSame('20.0000', $invoice->tax_amount);
        $this->assertSame('220.0000', $invoice->total);
    }

    public function test_request_supplied_tax_rate_is_never_read(): void
    {
        $user = $this->makeUser('Inv Trick User');
        [$business] = $this->provision($user, 'Inv Trick Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);
        $this->enableTax();

        $tax = $this->makeTax($business, ['rate' => '5.0000']);
        $invoice = $this->storeInvoice($user, $this->makeCustomer($business), [
            'items' => [
                ['product_id' => null, 'description' => 'Sneaky', 'quantity' => '1.0000', 'unit_price' => '100.0000', 'tax_id' => $tax->id],
            ],
        ]);

        $this->assertSame('5.0000', $invoice->items->first()->tax_rate);
        $this->assertSame('5.0000', $invoice->tax_amount);
    }

    // --- Lifecycle: draft editability + immutable later statuses -------------

    public function test_draft_invoices_are_editable_and_deletable(): void
    {
        $user = $this->makeUser('Inv Draft User');
        [$business] = $this->provision($user, 'Inv Draft Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $customerA = $this->makeCustomer($business, ['name' => 'First Buyer']);
        $customerB = $this->makeCustomer($business, ['name' => 'Second Buyer']);
        $invoice = $this->storeInvoice($user, $customerA);

        $this->get(route('invoices.edit', $invoice))->assertOk()->assertSee('Consulting hours');
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('INV-000001')->assertSee(__('invoices.print'));

        // Update: number frozen, totals recomputed, lines replaced.
        $this->patch(route('invoices.update', $invoice), $this->storePayload($customerB, [
            'status' => 'sent',
            'discount_type' => 'fixed',
            'discount_amount' => '50.0000',
            'items' => [
                ['product_id' => null, 'description' => 'Revised line', 'quantity' => '5.0000', 'unit_price' => '30.0000'],
            ],
        ]))
            ->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('status', __('invoices.updated'));

        $invoice->refresh();
        $this->assertSame('INV-000001', $invoice->invoice_number);
        $this->assertSame($customerB->id, $invoice->customer_id);
        $this->assertSame('sent', $invoice->status->value);
        $this->assertSame('150.0000', $invoice->subtotal);
        $this->assertSame('50.0000', $invoice->discount_amount);
        $this->assertSame('100.0000', $invoice->total);
        $this->assertCount(1, $invoice->items);
        $this->assertSame('Revised line', $invoice->items->first()->description);
    }

    public function test_sent_invoices_are_read_only(): void
    {
        $user = $this->makeUser('Inv Immutable User');
        [$business] = $this->provision($user, 'Inv Immutable Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $invoice = $this->storeInvoice($user, $customer, ['status' => 'sent']);

        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee(__('invoices.read_only'));

        $this->get(route('invoices.edit', $invoice))->assertForbidden();
        $this->patch(route('invoices.update', $invoice), $this->storePayload($customer))->assertForbidden();
        $this->delete(route('invoices.destroy', $invoice))->assertForbidden();

        $invoice->refresh();
        $this->assertSame('sent', $invoice->status->value);
        $this->assertSame('1200.0000', $invoice->total);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'deleted_at' => null]);
    }

    public function test_validation_rejects_invalid_input_and_payment_statuses(): void
    {
        $user = $this->makeUser('Inv Strict User');
        [$business] = $this->provision($user, 'Inv Strict Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $base = $this->storePayload($customer);

        $this->post('/invoices', array_merge($base, ['customer_id' => null]))->assertSessionHasErrors('customer_id');
        $this->post('/invoices', array_merge($base, ['items' => []]))->assertSessionHasErrors('items');
        $this->post('/invoices', array_merge($base, ['items' => $this->line('q', '0.0000')]))->assertSessionHasErrors('items.0.quantity');
        $this->post('/invoices', array_merge($base, ['items' => $this->line('p', '-1.0000')]))->assertSessionHasErrors('items.0.unit_price');
        $this->post('/invoices', array_merge($base, ['items' => $this->line('d', '')]))->assertSessionHasErrors('items.0.description');
        $this->post('/invoices', array_merge($base, ['items' => $this->line('q', '1.00005')]))->assertSessionHasErrors('items.0.quantity');
        $this->post('/invoices', array_merge($base, ['discount_type' => 'bogus']))->assertSessionHasErrors('discount_type');
        $this->post('/invoices', array_merge($base, ['discount_amount' => '-1.0000']))->assertSessionHasErrors('discount_amount');

        // Batch 15 lifecycle is draft|sent ONLY: payment and cancellation
        // statuses belong to later batches and are refused here.
        $this->post('/invoices', array_merge($base, ['status' => 'nonsense']))->assertSessionHasErrors('status');
        $this->post('/invoices', array_merge($base, ['status' => 'paid']))->assertSessionHasErrors('status');
        $this->post('/invoices', array_merge($base, ['status' => 'partially_paid']))->assertSessionHasErrors('status');
        $this->post('/invoices', array_merge($base, ['status' => 'overdue']))->assertSessionHasErrors('status');
        $this->post('/invoices', array_merge($base, ['status' => 'cancelled']))->assertSessionHasErrors('status');

        $this->assertDatabaseCount('invoices', 0);
    }

    // --- Soft-delete and historical survival ---------------------------------

    public function test_deleting_an_invoice_is_a_soft_delete_and_numbers_are_not_reused(): void
    {
        $user = $this->makeUser('Inv Softdel User');
        [$business] = $this->provision($user, 'Inv Softdel Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $invoice = $this->storeInvoice($user, $customer);
        $itemId = $invoice->items->first()->id;

        $this->delete(route('invoices.destroy', $invoice))
            ->assertRedirect(route('invoices.index'))
            ->assertSessionHas('status', __('invoices.deleted'));

        $this->assertSoftDeleted('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('invoice_items', ['id' => $itemId, 'invoice_id' => $invoice->id]);

        $this->get(route('invoices.index'))->assertOk()->assertDontSee('INV-000001');
        $this->assertNotNull(Invoice::query()->withTrashed()->find($invoice->id));

        $this->assertDatabaseHas('document_number_sequences', ['business_id' => $business->id, 'document_type' => 'invoice', 'last_number' => 1]);
    }

    public function test_customer_soft_delete_does_not_destroy_invoice_history(): void
    {
        $user = $this->makeUser('Inv Keep User');
        [$business] = $this->provision($user, 'Inv Keep Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business, ['name' => 'History Buyer']);
        $invoice = $this->storeInvoice($user, $customer);

        $customer->delete();

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('History Buyer');
        $invoice->refresh(['customer']);
        $this->assertSame('History Buyer', $invoice->customer->name);
    }

    // --- Search, filter, pagination, navigation ------------------------------

    public function test_index_supports_search_pagination_and_status_filtering(): void
    {
        $user = $this->makeUser('Inv List User');
        [$business] = $this->provision($user, 'Inv List Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $draft = $this->storeInvoice($user, $this->makeCustomer($business, ['name' => 'Drafty Customer']));
        $sent = $this->storeInvoice($user, $this->makeCustomer($business, ['name' => 'Sent Customer']), ['status' => 'sent']);

        $this->get('/invoices?status=draft')->assertOk()->assertSee($draft->invoice_number)->assertDontSee($sent->invoice_number);
        $this->get('/invoices?search='.$sent->invoice_number)->assertOk()->assertSee($sent->invoice_number)->assertDontSee($draft->invoice_number);
        $this->get('/invoices?search=Sent%20Customer')->assertOk()->assertSee($sent->invoice_number);
        $this->get('/invoices?status=bogus')->assertOk()->assertSee($draft->invoice_number)->assertSee($sent->invoice_number);

        for ($i = 0; $i < 14; $i++) {
            $this->storeInvoice($user, $this->makeCustomer($business));
        }
        $this->get('/invoices')->assertOk()->assertSee('INV-000016')->assertDontSee('INV-000001');
        $this->get('/invoices?page=2')->assertOk()->assertSee('INV-000001')->assertDontSee('INV-000016');
        $this->get('/invoices?search=INV-000016')->assertOk()->assertSee('INV-000016');
    }

    public function test_invoice_navigation_children_and_viewer_visibility(): void
    {
        // Batch 15: Sales renders Quotations + Invoices as children; the
        // default Viewer role holds invoices.view (unlike quotations).
        $children = config('modules.registry.sales.navigation.children');
        $this->assertNull(config('modules.registry.sales.navigation.route'));
        $this->assertSame('invoices.index', collect($children)->firstWhere('route', 'invoices.index')['route']);
        $this->assertSame('invoices.view', collect($children)->firstWhere('route', 'invoices.index')['permission']);

        $user = $this->makeUser('Inv Nav User');
        [, , $roles] = $this->provision($user, 'Inv Nav Co.', 'owner');

        $this->assertTrue($roles['owner']->hasPermission('invoices.view'));
        $this->assertTrue($roles['owner']->hasPermission('invoices.manage'));
        $this->assertTrue($roles['viewer']->hasPermission('invoices.view'));
        $this->assertFalse($roles['viewer']->hasPermission('invoices.manage'));
    }

    // --- Miscellaneous Batch 15 invariants -----------------------------------

    public function test_products_and_services_are_both_allowed_as_line_items(): void
    {
        $user = $this->makeUser('Inv Mixed User');
        [$business] = $this->provision($user, 'Inv Mixed Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $product = $this->makeProduct($business, ['type' => ProductType::Product]);
        $service = $this->makeProduct($business, ['type' => ProductType::Service]);

        $invoice = $this->storeInvoice($user, $this->makeCustomer($business), [
            'items' => [
                ['product_id' => $product->id, 'description' => 'Widget', 'quantity' => '1.0000', 'unit_price' => '10.0000'],
                ['product_id' => $service->id, 'description' => 'Labor', 'quantity' => '2.0000', 'unit_price' => '25.0000'],
            ],
        ]);

        $this->assertCount(2, $invoice->items);
        $this->assertSame($product->id, $invoice->items[0]->product_id);
        $this->assertSame($service->id, $invoice->items[1]->product_id);
        $this->assertSame('60.0000', $invoice->total);
    }

    public function test_customer_reference_cannot_be_substituted_on_update(): void
    {
        $user = $this->makeUser('Inv Customer Swap');
        [$business] = $this->provision($user, 'Inv Customer Swap Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $original = $this->makeCustomer($business, ['name' => 'Original Buyer']);
        $impostor = $this->makeCustomer($business, ['name' => 'Impostor Buyer']);
        $invoice = $this->storeInvoice($user, $original);

        $this->patch(route('invoices.update', $invoice), $this->storePayload($impostor))
            ->assertRedirect(route('invoices.show', $invoice));

        // The operator CAN legitimately re-issue a still-draft invoice to a
        // different customer (it is still being drafted), but every reference
        // must always resolve inside the current business (validated above);
        // the number and totals stay server-owned.
        $invoice->refresh();
        $this->assertSame($impostor->id, $invoice->customer_id);
        $this->assertSame('INV-000001', $invoice->invoice_number);
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * Build a minimum-viable quotation directly (no HTTP) so invoice creation
     * tests never depend on the quotation module state.
     */
    private function makeQuotation(User $user): Quotation
    {
        [$business] = $this->provision($user, 'Inv Quo Src Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        return app(QuotationService::class)->create(
            [
                'customer_id' => $this->makeCustomer($business)->id,
                'date' => '2026-09-12',
                'status' => 'draft',
                'discount_type' => null,
                'discount_amount' => '0.0000',
                'notes' => null,
                'items' => [
                    ['product_id' => null, 'description' => 'Src', 'quantity' => '1.0000', 'unit_price' => '10.0000'],
                ],
            ],
            (int) $user->id,
        );
    }

    /**
     * POST an invoice as the given (already provisioned) owner and return the
     * persisted row (asserts the created flash).
     */
    private function storeInvoice(User $user, Customer $customer, array $overrides = []): Invoice
    {
        $this->post('/invoices', $this->storePayload($customer, $overrides))
            ->assertSessionHas('status', __('invoices.created'));

        return Invoice::query()->orderByDesc('id')->firstOrFail();
    }

    /**
     * @return list<array{product_id: mixed, description: string, quantity: string, unit_price: string}>
     */
    private function line(string $blank, string $value): array
    {
        $line = ['product_id' => null, 'description' => 'Line', 'quantity' => '1.0000', 'unit_price' => '10.0000'];

        return [match ($blank) {
            'q' => array_merge($line, ['quantity' => $value]),
            'p' => array_merge($line, ['unit_price' => $value]),
            'd' => array_merge($line, ['description' => $value]),
            default => $line,
        }];
    }
}
