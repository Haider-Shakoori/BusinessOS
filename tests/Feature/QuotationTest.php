<?php

namespace Tests\Feature;

use App\Enums\ProductType;
use App\Enums\QuotationStatus;
use App\Http\Requests\Quotation\StoreQuotationRequest;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Tax;
use App\Models\User;
use App\Services\BusinessSettings;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Quotation CRUD + tenancy + numbering + lifecycle (Batch 14).
 *
 * Quotations live under the `sales` module with a read/write permission split
 * (quotations.view / quotations.manage). Numbers come from the Batch 13
 * DocumentNumberService inside the same transaction as the insert. Draft
 * quotations are editable/deletable; every later status is read-only in this
 * batch. Tax is optional: while general.tax_enabled is off the request never
 * sees a tax rule and every line is written tax-free.
 *
 * Invoices (Batch 15) are covered by InvoiceTest and QuotationConversionTest;
 * since Batch 15 they also live under `sales` as navigation children.
 */
class QuotationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        // Fresh in-memory connection, never migrate:fresh or a developer database.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    private function makeUser(string $name = 'Quotation User'): User
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

    /**
     * Enable the optional-tax feature through BusinessSettings — the same
     * authoritative source the rules/UI/Service consult. Call after actIn().
     */
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
            'expiry_date' => '2026-09-30',
            'status' => 'draft',
            'discount_type' => null,
            'discount_amount' => '',
            'notes' => 'First quote notes.',
            'terms' => 'Terms and conditions.',
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

    public function test_quotation_schema_is_in_place(): void
    {
        $this->assertTrue(Schema::hasTable('quotations'));
        $this->assertTrue(Schema::hasTable('quotation_items'));

        foreach (['business_id', 'quotation_number', 'customer_id', 'date', 'expiry_date', 'status', 'subtotal', 'discount_type', 'discount_amount', 'tax_amount', 'total', 'notes', 'terms', 'created_by', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('quotations', $column), "Expected quotations.$column to exist.");
        }

        // quotation_items has NO soft-delete column (approved Batch 14 schema):
        // deleting a quotation soft-deletes only the header; the item snapshot
        // rows stay behind attached to the trashed header.
        foreach (['quotation_id', 'product_id', 'description', 'quantity', 'unit_price', 'tax_id', 'tax_rate', 'line_subtotal', 'line_tax', 'line_total', 'sort_order', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('quotation_items', $column), "Expected quotation_items.$column to exist.");
        }

        $this->assertTrue(Schema::hasIndex('quotations', ['business_id', 'quotation_number']));
    }

    // --- Route guarding: auth + business + module + permission ---------------

    public function test_quotation_routes_require_authentication_and_a_business(): void
    {
        $this->get('/quotations')->assertRedirect(route('login'));
        $this->get('/quotations/create')->assertRedirect(route('login'));
        $this->post('/quotations', ['status' => 'draft'])->assertRedirect(route('login'));

        // Authenticated but with no business: onboarding state, never the list.
        $user = $this->makeUser();
        $this->actingAs($user)->get('/quotations')->assertRedirect(route('business.create'));
    }

    public function test_quotation_module_guard_blocks_when_disabled(): void
    {
        $user = $this->makeUser('Quo Owner');
        [$business] = $this->provision($user, 'Quo Co.', 'owner');
        $this->actIn($user, $business);

        // Owner holds the permissions, but sales is disabled -> forbidden.
        $this->assertTrue(Gate::allows('quotations.view'));
        $this->get('/quotations')->assertForbidden();
        $this->get('/quotations/create')->assertForbidden();
        $this->post('/quotations', ['status' => 'draft'])->assertForbidden();

        // Enable the module -> the same owner is served.
        $this->enableSales($business);
        $this->get('/quotations')->assertOk();

        // Disable again -> refused outright, no data between requests.
        $business->modules()->where('module_key', 'sales')->update(['enabled' => false]);
        $this->get('/quotations')->assertForbidden();
    }

    public function test_reads_require_quotations_view_and_writes_require_quotations_manage(): void
    {
        $user = $this->makeUser('No Perm');
        [$business, , $roles] = $this->provision($user, 'No Perm Sales Co.', 'viewer');
        $this->enableSales($business);
        $this->actIn($user, $business);

        // The default viewer role holds neither quotation permission.
        $this->assertFalse(Gate::allows('quotations.view'));
        $this->get('/quotations')->assertForbidden();
        $this->get('/quotations/create')->assertForbidden();

        // Grant view only: reads work, every write path is denied.
        $viewId = Permission::where('name', 'quotations.view')->value('id');
        $roles['viewer']->permissions()->syncWithoutDetaching([$viewId]);
        $this->rebuildContext();
        $this->actIn($user, $business);
        $this->assertTrue(Gate::allows('quotations.view'));
        $this->assertFalse(Gate::allows('quotations.manage'));

        $customer = $this->makeCustomer($business);
        $this->get('/quotations')->assertOk()->assertSee(__('quotations.view_only'));
        $this->get('/quotations/create')->assertForbidden();
        $this->post('/quotations', $this->storePayload($customer))->assertForbidden();
        $this->assertDatabaseCount('quotations', 0);

        // Grant manage as well: the same payload is now created.
        $manageId = Permission::where('name', 'quotations.manage')->value('id');
        $roles['viewer']->permissions()->syncWithoutDetaching([$manageId]);
        $quotation = $this->storeQuotation($user, $customer);

        $this->actIn($user, $business);
        $this->assertSame('QUO-000001', $quotation->quotation_number);
    }

    // --- Owner happy path: exact totals + numbering --------------------------

    public function test_owner_can_create_with_exact_server_computed_totals(): void
    {
        $user = $this->makeUser('Quo Creator');
        [$business] = $this->provision($user, 'Totals Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business, ['name' => 'Acme Trading']);
        $product = $this->makeProduct($business);

        $this->post('/quotations', $this->storePayload($customer, [
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
            ->assertRedirect(route('quotations.show', Quotation::query()->orderByDesc('id')->firstOrFail()))
            ->assertSessionHas('status', __('quotations.created'));

        $quotation = Quotation::query()->orderByDesc('id')->firstOrFail();

        // Number came from the Batch 13 sequence inside the same transaction.
        $this->assertSame('QUO-000001', $quotation->quotation_number);
        $this->assertSame($business->id, $quotation->business_id);
        $this->assertSame($customer->id, $quotation->customer_id);
        $this->assertSame($user->id, $quotation->created_by);

        // Exact arithmetic: two lines (200.0000 + 151.5000), 10% off, no tax.
        $this->assertSame('351.5000', $quotation->subtotal);
        $this->assertSame('0.0000', $quotation->tax_amount);
        $this->assertSame('percentage', $quotation->discount_type);
        $this->assertSame('10.0000', $quotation->discount_amount);
        $this->assertSame('316.3500', $quotation->total);

        $this->assertCount(2, $quotation->items);
        $first = $quotation->items[0];
        $this->assertSame('200.0000', $first->line_subtotal);
        $this->assertSame('0.0000', $first->line_tax);
        $this->assertSame('200.0000', $first->line_total);
        $second = $quotation->items[1];
        $this->assertSame('151.5000', $second->line_subtotal);

        // The product reference is kept; the snapshot description is stored.
        $this->assertSame($product->id, $first->product_id);
        $this->assertSame('Consulting hours', $first->description);
        $this->assertSame(0, $first->sort_order);
        $this->assertSame(1, $second->sort_order);

        $this->assertDatabaseHas('document_number_sequences', ['business_id' => $business->id, 'document_type' => 'quotation', 'last_number' => 1]);
    }

    public function test_create_ignores_forged_business_number_and_totals(): void
    {
        $user = $this->makeUser('Forger');
        [$business, , $roles] = $this->provision($user, 'Trusted Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $this->post('/quotations', $this->storePayload($customer, [
            'business_id' => 999,
            'quotation_number' => 'HACKED-999999',
            'subtotal' => '0.0001',
            'tax_amount' => '999.0000',
            'total' => '0.0001',
            'items' => [
                ['product_id' => null, 'description' => 'One', 'quantity' => '1.0000', 'unit_price' => '100.0000'],
            ],
        ]))->assertSessionHas('status', __('quotations.created'));

        $quotation = Quotation::query()->firstOrFail();
        $this->assertSame('QUO-000001', $quotation->quotation_number);
        $this->assertSame($business->id, $quotation->business_id);
        $this->assertSame('100.0000', $quotation->subtotal);
        $this->assertSame('0.0000', $quotation->tax_amount);
        $this->assertSame('100.0000', $quotation->total);

        $this->assertFalse(Quotation::query()->where('quotation_number', 'HACKED-999999')->exists());
    }

    public function test_numbers_increment_per_business_and_are_never_reused(): void
    {
        $user = $this->makeUser('Seq User');
        [$businessA] = $this->provision($user, 'Seq A Co.', 'owner');
        [$businessB] = $this->provision($user, 'Seq B Co.', 'owner');
        $this->enableSales($businessA);
        $this->enableSales($businessB);

        $this->actIn($user, $businessA);
        $a1 = $this->storeQuotation($user, $this->makeCustomer($businessA));
        $a2 = $this->storeQuotation($user, $this->makeCustomer($businessA));
        $this->assertSame('QUO-000001', $a1->quotation_number);
        $this->assertSame('QUO-000002', $a2->quotation_number);

        // Business B starts its own counter at 1.
        $this->actIn($user, $businessB);
        $b1 = $this->storeQuotation($user, $this->makeCustomer($businessB));
        $this->assertSame('QUO-000001', $b1->quotation_number);

        // Deleting a draft never frees the number: the next one skips ahead.
        $this->actIn($user, $businessA);
        $quotation = $this->storeQuotation($user, $this->makeCustomer($businessA));
        $this->assertSame('QUO-000003', $quotation->quotation_number);
        $this->delete(route('quotations.destroy', $quotation))
            ->assertRedirect(route('quotations.index'))
            ->assertSessionHas('status', __('quotations.deleted'));

        $next = $this->storeQuotation($user, $this->makeCustomer($businessA));
        $this->assertSame('QUO-000004', $next->quotation_number);
    }

    public function test_failed_validation_consumes_no_number(): void
    {
        $user = $this->makeUser('Rigid User');
        [$business] = $this->provision($user, 'Rigid Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        // NOTE: array_replace_recursive (used by the payload helper) treats an
        // empty array as "merge nothing in", so it must be cleared explicitly.
        $payload = $this->storePayload($this->makeCustomer($business));
        $payload['items'] = [];

        $this->post('/quotations', $payload)->assertSessionHasErrors('items');

        // No quotation and no sequence row were written.
        $this->assertDatabaseCount('quotations', 0);
        $this->assertDatabaseCount('document_number_sequences', 0);
    }

    // --- Tenancy: cross-business isolation -----------------------------------

    public function test_cross_business_show_edit_update_and_delete_are_blocked(): void
    {
        $user = $this->makeUser('Tenant User');
        [$businessA] = $this->provision($user, 'Tenant A Co.', 'owner');
        [$businessB] = $this->provision($user, 'Tenant B Co.', 'owner');
        $this->enableSales($businessA);
        $this->enableSales($businessB);

        $this->actIn($user, $businessA);
        $quotation = $this->storeQuotation($user, $this->makeCustomer($businessA));
        $this->assertSame('QUO-000001', $quotation->quotation_number);

        $this->actIn($user, $businessB);
        $this->get(route('quotations.index'))->assertOk()->assertDontSee('QUO-000001')->assertSee(__('quotations.no_quotations'));
        $this->get(route('quotations.show', $quotation))->assertNotFound();
        $this->get(route('quotations.edit', $quotation))->assertNotFound();
        $this->patch(route('quotations.update', $quotation), $this->storePayload($this->makeCustomer($businessB)))->assertNotFound();
        $this->delete(route('quotations.destroy', $quotation))->assertNotFound();

        $this->assertDatabaseHas('quotations', ['id' => $quotation->id, 'business_id' => $businessA->id, 'deleted_at' => null]);
    }

    public function test_index_and_search_show_only_current_business_quotations(): void
    {
        $user = $this->makeUser('Isolation User');
        [$businessA] = $this->provision($user, 'Isolation A Co.', 'owner');
        [$businessB] = $this->provision($user, 'Isolation B Co.', 'owner');
        $this->enableSales($businessA);
        $this->enableSales($businessB);

        $this->actIn($user, $businessA);
        $this->storeQuotation($user, $this->makeCustomer($businessA, ['name' => 'Alpha Buyer']));
        $this->actIn($user, $businessB);
        $this->storeQuotation($user, $this->makeCustomer($businessB, ['name' => 'Beta Buyer']));

        // Business B sees only its own rows, even by search.
        $this->actIn($user, $businessB);
        $this->get('/quotations')->assertOk()->assertSee('Beta Buyer')->assertDontSee('Alpha Buyer');
        $this->get('/quotations?search=Beta')->assertOk()->assertSee('Beta Buyer')->assertDontSee('Alpha Buyer');
        $this->get('/quotations?search=Alpha')->assertOk()->assertDontSee('Beta Buyer')->assertSee(__('quotations.no_results'));
    }

    public function test_cross_business_fk_references_are_rejected_at_validation(): void
    {
        $user = $this->makeUser('Fk User');
        [$businessA] = $this->provision($user, 'Fk A Co.', 'owner');
        [$businessB] = $this->provision($user, 'Fk B Co.', 'owner');
        $this->enableSales($businessA);
        $this->actIn($user, $businessA);

        $customerB = $this->makeCustomer($businessB);
        $productB = $this->makeProduct($businessB);
        $taxB = $this->makeTax($businessB);

        // Foreign customer -> rejected.
        $this->post('/quotations', $this->storePayload($customerB))->assertSessionHasErrors('customer_id');
        // Foreign product -> rejected (only when it reaches validation, i.e. own customer).
        $this->post('/quotations', $this->storePayload($this->makeCustomer($businessA), [
            'items' => [
                ['product_id' => $productB->id, 'description' => 'Nope', 'quantity' => '1.0000', 'unit_price' => '10.0000'],
            ],
        ]))->assertSessionHasErrors('items.0.product_id');
        // No rows were written anywhere.
        $this->assertDatabaseCount('quotations', 0);

        // Foreign tax_id is only a rule while tax is enabled.
        $this->enableTax();
        $this->post('/quotations', $this->storePayload($this->makeCustomer($businessA), [
            'items' => [
                ['product_id' => null, 'description' => 'Nope', 'quantity' => '1.0000', 'unit_price' => '10.0000', 'tax_id' => $taxB->id],
            ],
        ]))->assertSessionHasErrors('items.0.tax_id');
        $this->assertDatabaseCount('quotations', 0);
    }

    // --- Optional tax --------------------------------------------------------

    public function test_tax_disabled_ignores_a_forged_tax_id_and_writes_tax_free_lines(): void
    {
        $user = $this->makeUser('No Tax User');
        [$business] = $this->provision($user, 'No Tax Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $tax = $this->makeTax($business, ['rate' => '20.0000']);
        $quotation = $this->storeQuotation($user, $this->makeCustomer($business), [
            'items' => [
                ['product_id' => null, 'description' => 'Taxed?', 'quantity' => '1.0000', 'unit_price' => '100.0000', 'tax_id' => $tax->id],
            ],
        ]);

        $item = $quotation->items->first();
        $this->assertNull($item->tax_id);
        $this->assertNull($item->tax_rate);
        $this->assertSame('0.0000', $item->line_tax);
        $this->assertSame('100.0000', $quotation->subtotal);
        $this->assertSame('0.0000', $quotation->tax_amount);
        $this->assertSame('100.0000', $quotation->total);
    }

    public function test_tax_enabled_snapshots_the_tax_rate_from_the_live_tax_row(): void
    {
        $user = $this->makeUser('Tax User');
        [$business] = $this->provision($user, 'Tax Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);
        $this->enableTax();

        $tax = $this->makeTax($business, ['rate' => '20.0000']);
        $quotation = $this->storeQuotation($user, $this->makeCustomer($business), [
            'items' => [
                ['product_id' => null, 'description' => 'Widget', 'quantity' => '2.0000', 'unit_price' => '100.0000', 'tax_id' => $tax->id],
                ['product_id' => null, 'description' => 'Setup', 'quantity' => '1.0000', 'unit_price' => '50.0000'],
            ],
        ]);

        // Line 1: 200 subtotal + 40 tax (20%), line 2 tax-free.
        $first = $quotation->items[0];
        $this->assertSame($tax->id, $first->tax_id);
        $this->assertSame('20.0000', $first->tax_rate);
        $this->assertSame('200.0000', $first->line_subtotal);
        $this->assertSame('40.0000', $first->line_tax);
        $this->assertSame('240.0000', $first->line_total);

        $second = $quotation->items[1];
        $this->assertNull($second->tax_rate);
        $this->assertSame('0.0000', $second->line_tax);

        $this->assertSame('250.0000', $quotation->subtotal);
        $this->assertSame('40.0000', $quotation->tax_amount);
        $this->assertSame('290.0000', $quotation->total);
    }

    public function test_changing_the_tax_rate_later_never_rewrites_stored_snapshots(): void
    {
        $user = $this->makeUser('Snapshot User');
        [$business] = $this->provision($user, 'Snapshot Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);
        $this->enableTax();

        $tax = $this->makeTax($business, ['rate' => '10.0000']);
        $quotation = $this->storeQuotation($user, $this->makeCustomer($business), [
            'items' => [
                ['product_id' => null, 'description' => 'Widget', 'quantity' => '1.0000', 'unit_price' => '200.0000', 'tax_id' => $tax->id],
            ],
        ]);
        $this->assertSame('10.0000', $quotation->items->first()->tax_rate);
        $this->assertSame('20.0000', $quotation->tax_amount);

        // Raise the live rate: the stored quotation must not move.
        $tax->rate = '99.0000';
        $tax->save();

        $quotation->refresh();
        $this->assertSame('10.0000', $quotation->items->first()->tax_rate);
        $this->assertSame('20.0000', $quotation->tax_amount);
        $this->assertSame('220.0000', $quotation->total);
    }

    public function test_request_supplied_tax_rate_is_never_read(): void
    {
        $user = $this->makeUser('Trick User');
        [$business] = $this->provision($user, 'Trick Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);
        $this->enableTax();

        $tax = $this->makeTax($business, ['rate' => '5.0000']);
        $quotation = $this->storeQuotation($user, $this->makeCustomer($business), [
            'items' => [
                ['product_id' => null, 'description' => 'Sneaky', 'quantity' => '1.0000', 'unit_price' => '100.0000', 'tax_id' => $tax->id],
            ],
        ]);

        // Snapshot came from the Tax row (5%), never a client-supplied rate.
        $this->assertSame('5.0000', $quotation->items->first()->tax_rate);
        $this->assertSame('5.0000', $quotation->tax_amount);
    }

    // --- Lifecycle: draft editability + immutable later statuses -------------

    public function test_draft_quotations_are_editable_and_deletable(): void
    {
        $user = $this->makeUser('Draft User');
        [$business] = $this->provision($user, 'Draft Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $customerA = $this->makeCustomer($business, ['name' => 'First Buyer']);
        $customerB = $this->makeCustomer($business, ['name' => 'Second Buyer']);
        $quotation = $this->storeQuotation($user, $customerA);

        $this->get(route('quotations.edit', $quotation))->assertOk()->assertSee('Consulting hours');
        $this->get(route('quotations.show', $quotation))->assertOk()->assertSee('QUO-000001')->assertSee(__('quotations.print'));

        // Update: number frozen, totals recomputed, lines replaced.
        $this->patch(route('quotations.update', $quotation), $this->storePayload($customerB, [
            'status' => 'sent',
            'discount_type' => 'fixed',
            'discount_amount' => '50.0000',
            'items' => [
                ['product_id' => null, 'description' => 'Revised line', 'quantity' => '5.0000', 'unit_price' => '30.0000'],
            ],
        ]))
            ->assertRedirect(route('quotations.show', $quotation))
            ->assertSessionHas('status', __('quotations.updated'));

        $quotation->refresh();
        $this->assertSame('QUO-000001', $quotation->quotation_number);
        $this->assertSame($customerB->id, $quotation->customer_id);
        $this->assertSame('sent', $quotation->status->value);
        $this->assertSame('150.0000', $quotation->subtotal);
        $this->assertSame('50.0000', $quotation->discount_amount);
        $this->assertSame('100.0000', $quotation->total);
        $this->assertCount(1, $quotation->items);
        $this->assertSame('Revised line', $quotation->items->first()->description);
    }

    public function test_non_draft_quotations_are_read_only(): void
    {
        $user = $this->makeUser('Immutable User');
        [$business] = $this->provision($user, 'Immutable Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $quotation = $this->storeQuotation($user, $customer, ['status' => 'sent']);

        // Read-only, but viewable.
        $this->get(route('quotations.show', $quotation))->assertOk()->assertSee(__('quotations.read_only'));

        // Every write path is a hard 403, even with a fully valid payload.
        $this->get(route('quotations.edit', $quotation))->assertForbidden();
        $this->patch(route('quotations.update', $quotation), $this->storePayload($customer))->assertForbidden();
        $this->delete(route('quotations.destroy', $quotation))->assertForbidden();

        // The document is untouched: still present, not soft-deleted, and exactly
        // as it was written.
        $quotation->refresh();
        $this->assertSame('sent', $quotation->status->value);
        $this->assertSame('1200.0000', $quotation->total);
        $this->assertDatabaseHas('quotations', ['id' => $quotation->id, 'deleted_at' => null]);
    }

    public function test_converted_status_is_reserved_and_cannot_be_set_in_batch_14(): void
    {
        $user = $this->makeUser('Reserved User');
        [$business] = $this->provision($user, 'Reserved Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $this->post('/quotations', $this->storePayload($this->makeCustomer($business), [
            'status' => 'converted',
        ]))->assertSessionHasErrors('status');

        $this->assertInstanceOf(QuotationStatus::class, QuotationStatus::Converted);
        $this->assertSame('converted', QuotationStatus::Converted->value);
        $this->assertNotContains('converted', StoreQuotationRequest::settableStatuses());
        $this->assertDatabaseCount('quotations', 0);
    }

    public function test_validation_rejects_invalid_input(): void
    {
        $user = $this->makeUser('Strict User');
        [$business] = $this->provision($user, 'Strict Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $base = $this->storePayload($customer);

        // Missing customer.
        $this->post('/quotations', array_merge($base, ['customer_id' => null]))->assertSessionHasErrors('customer_id');
        // Empty items.
        $this->post('/quotations', array_merge($base, ['items' => []]))->assertSessionHasErrors('items');
        // Quantity must be > 0.
        $this->post('/quotations', array_merge($base, ['items' => $this->line('q', '0.0000')]))->assertSessionHasErrors('items.0.quantity');
        // Unit price may not be negative.
        $this->post('/quotations', array_merge($base, ['items' => $this->line('p', '-1.0000')]))->assertSessionHasErrors('items.0.unit_price');
        // Description required.
        $this->post('/quotations', array_merge($base, ['items' => $this->line('d', '')]))->assertSessionHasErrors('items.0.description');
        // More than 4 decimals are refused.
        $this->post('/quotations', array_merge($base, ['items' => $this->line('q', '1.00005')]))->assertSessionHasErrors('items.0.quantity');
        // Expiry cannot precede the quote date.
        $this->post('/quotations', array_merge($base, ['expiry_date' => '2026-09-11']))->assertSessionHasErrors('expiry_date');
        // Invalid discount type.
        $this->post('/quotations', array_merge($base, ['discount_type' => 'bogus']))->assertSessionHasErrors('discount_type');
        // Negative discount amount.
        $this->post('/quotations', array_merge($base, ['discount_amount' => '-1.0000']))->assertSessionHasErrors('discount_amount');
        // Unsupported status.
        $this->post('/quotations', array_merge($base, ['status' => 'nonsense']))->assertSessionHasErrors('status');

        $this->assertDatabaseCount('quotations', 0);
    }

    // --- Soft-delete and historical survival ---------------------------------

    public function test_deleting_a_quotation_is_a_soft_delete_and_numbers_are_not_reused(): void
    {
        $user = $this->makeUser('Softdel User');
        [$business] = $this->provision($user, 'Softdel Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business);
        $quotation = $this->storeQuotation($user, $customer);
        $itemId = $quotation->items->first()->id;

        $this->delete(route('quotations.destroy', $quotation))
            ->assertRedirect(route('quotations.index'))
            ->assertSessionHas('status', __('quotations.deleted'));

        $this->assertSoftDeleted('quotations', ['id' => $quotation->id]);
        $this->assertDatabaseHas('quotation_items', ['id' => $itemId, 'quotation_id' => $quotation->id]);

        // The list no longer shows it, but history survives withTrashed.
        $this->get(route('quotations.index'))->assertOk()->assertDontSee('QUO-000001');
        $this->assertNotNull(Quotation::query()->withTrashed()->find($quotation->id));

        // The sequence still holds the consumed number; nothing rewound.
        $this->assertDatabaseHas('document_number_sequences', ['business_id' => $business->id, 'document_type' => 'quotation', 'last_number' => 1]);
    }

    public function test_customer_soft_delete_does_not_destroy_quotation_history(): void
    {
        $user = $this->makeUser('Keep User');
        [$business] = $this->provision($user, 'Keep Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $customer = $this->makeCustomer($business, ['name' => 'History Buyer']);
        $quotation = $this->storeQuotation($user, $customer);

        // Soft-delete the customer.
        $customer->delete();

        // The quotation survives and still resolves the historical customer.
        $this->get(route('quotations.show', $quotation))
            ->assertOk()
            ->assertSee('History Buyer');
        $quotation->refresh(['customer']);
        $this->assertSame('History Buyer', $quotation->customer->name);
    }

    // --- Search, filter, pagination, navigation ------------------------------

    public function test_index_supports_search_pagination_and_status_filtering(): void
    {
        $user = $this->makeUser('List User');
        [$business] = $this->provision($user, 'List Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $draft = $this->storeQuotation($user, $this->makeCustomer($business, ['name' => 'Drafty Customer']));
        $sent = $this->storeQuotation($user, $this->makeCustomer($business, ['name' => 'Sent Customer']), ['status' => 'sent']);

        // Status filter: only drafts.
        $this->get('/quotations?status=draft')->assertOk()->assertSee($draft->quotation_number)->assertDontSee($sent->quotation_number);
        // Search by number.
        $this->get('/quotations?search='.$sent->quotation_number)->assertOk()->assertSee($sent->quotation_number)->assertDontSee($draft->quotation_number);
        // Search by customer name.
        $this->get('/quotations?search=Sent%20Customer')->assertOk()->assertSee($sent->quotation_number);
        // Invalid status filter silently falls back to the full list.
        $this->get('/quotations?status=bogus')->assertOk()->assertSee($draft->quotation_number)->assertSee($sent->quotation_number);

        // Pagination: 15 per page; the 16th row lands on page 2.
        for ($i = 0; $i < 14; $i++) {
            $this->storeQuotation($user, $this->makeCustomer($business));
        }
        $this->get('/quotations')->assertOk()->assertSee('QUO-000016')->assertDontSee('QUO-000001');
        $this->get('/quotations?page=2')->assertOk()->assertSee('QUO-000001')->assertDontSee('QUO-000016');
        $this->get('/quotations?search=QUO-000016')->assertOk()->assertSee('QUO-000016');
    }

    public function test_quotation_navigation_is_a_sales_child_entry_with_its_own_permission(): void
    {
        // Batch 15: the Sales module renders Quotations + Invoices as children
        // (no parent route), each gated by its own read permission.
        $children = config('modules.registry.sales.navigation.children');
        $quotationChild = collect($children)->firstWhere('route', 'quotations.index');
        $this->assertSame('quotations.view', $quotationChild['permission']);
        $this->assertSame('invoices.index', collect($children)->firstWhere('route', 'invoices.index')['route']);

        $user = $this->makeUser('Nav User');
        [$business] = $this->provision($user, 'Nav Co.', 'owner');

        // Sales disabled -> no child appears in the sidebar.
        $this->actIn($user, $business);
        $this->get('/app')->assertOk()->assertDontSee(__('modules.quotations'))->assertDontSee(__('modules.invoices'));

        // Enabled for an owner (holds quotations.view + invoices.view) -> both
        // entries appear.
        $this->enableSales($business);
        $this->rebuildContext();
        $this->get('/app')->assertOk()->assertSee(__('modules.quotations'))->assertSee(__('modules.invoices'));
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * POST a quotation as the given (already provisioned) owner and return the
     * persisted row (asserts the show redirect + created flash).
     */
    private function storeQuotation(User $user, Customer $customer, array $overrides = []): Quotation
    {
        $this->post('/quotations', $this->storePayload($customer, $overrides))
            ->assertSessionHas('status', __('quotations.created'));

        return Quotation::query()->orderByDesc('id')->firstOrFail();
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
