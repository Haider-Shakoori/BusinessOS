<?php

namespace Tests\Feature;

use App\Enums\QuotationStatus;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Tax;
use App\Models\User;
use App\Services\BusinessSettings;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Quotation -> Invoice conversion (Batch 15).
 *
 * Conversion is transactional: one invoice (status Sent, terminal + immutable
 * in this batch), a fresh DocumentNumberService::next(DocumentType::Invoice)
 * number allocated inside the same transaction, item/customer/discount/notes
 * snapshots copied verbatim, and totals RECOMPUTED from those locked snapshots
 * so the converted invoice mathematically equals the source quotation with
 * zero drift. The source quotation is marked Converted (terminal) and the
 * one-to-one guarantee is enforced both by a row lock + status re-check and by
 * the database's unique index on invoices.quotation_id, so a concurrent double
 * convert can never produce an orphan invoice or burn a number.
 *
 * Invoices live under the `sales` module and conversion needs BOTH
 * permissions: quotations.view (access to the source document) and
 * invoices.manage (write the new document).
 */
class QuotationConversionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    private function makeUser(string $name = 'Convert User'): User
    {
        return User::create(['name' => $name, 'email' => Str::random(10).'@example.test', 'password' => Hash::make('password')]);
    }

    private function makeBusiness(string $name): Business
    {
        return Business::create(['name' => $name]);
    }

    /**
     * @return array{business: Business, membership: mixed, roles: array<string, Role>}
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

    private function makeCustomer(Business $business, string $name = 'Convert Buyer'): Customer
    {
        $customer = new Customer(['name' => $name, 'company_name' => 'Company', 'email' => Str::random(8).'@example.test']);
        $customer->business_id = $business->id;
        $customer->save();

        return $customer;
    }

    private function makeTax(Business $business, float|string $rate = '20.0000'): Tax
    {
        $tax = new Tax(['name' => 'VAT', 'rate' => (string) $rate]);
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
     * @return array<string, mixed>
     */
    private function quotationPayload(Customer $customer, array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $customer->id,
            'date' => '2026-09-12',
            'status' => 'draft',
            'discount_type' => null,
            'discount_amount' => '',
            'notes' => null,
            'items' => [
                ['product_id' => null, 'description' => 'Original line', 'quantity' => '10.0000', 'unit_price' => '120.0000'],
            ],
        ], $overrides);
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
            'notes' => 'Final.',
            'items' => [
                ['product_id' => null, 'description' => 'Anything', 'quantity' => '1.0000', 'unit_price' => '10.0000'],
            ],
        ], $overrides);
    }

    /**
     * POST /quotations as the current user and return the persisted quotation.
     */
    private function storeQuotation(Customer $customer, array $overrides = []): Quotation
    {
        $this->post('/quotations', $this->quotationPayload($customer, $overrides))
            ->assertSessionHas('status', __('quotations.created'));

        return Quotation::query()->orderByDesc('id')->firstOrFail();
    }

    private function convert(Quotation $quotation): TestResponse
    {
        return $this->post(route('quotations.convert', $quotation));
    }

    private function assertExactlyOneInvoice(int $businessId, int $quotationId): Invoice
    {
        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('document_number_sequences', ['business_id' => $businessId, 'document_type' => 'invoice', 'last_number' => 1]);

        $invoice = Invoice::query()->where('business_id', $businessId)->firstOrFail();
        $this->assertSame($quotationId, $invoice->quotation_id);
        $this->assertSame('INV-000001', $invoice->invoice_number);

        return $invoice;
    }

    // --- Happy path ----------------------------------------------------------

    public function test_conversion_creates_a_sent_invoice_with_matching_snapshots_and_marks_the_quotation_converted(): void
    {
        $user = $this->makeUser('Convert Happy User');
        [$business] = $this->provision($user, 'Convert Happy Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $quotation = $this->storeQuotation($this->makeCustomer($business), [
            'notes' => 'Convert me.',
            'items' => [
                ['product_id' => null, 'description' => 'Original line', 'quantity' => '10.0000', 'unit_price' => '120.0000'],
            ],
        ]);
        $this->assertSame('QUO-000001', $quotation->quotation_number);

        $response = $this->convert($quotation);
        $response->assertSessionHas('status', __('invoices.converted'));

        $invoice = $this->assertExactlyOneInvoice($business->id, $quotation->id);
        $response->assertRedirect(route('invoices.show', $invoice));

        // Header: server-built, status Sent, safe references, copies verbatim.
        $this->assertSame('sent', $invoice->status->value);
        $this->assertSame($business->id, $invoice->business_id);
        $this->assertSame($quotation->customer_id, $invoice->customer_id);
        $this->assertSame($user->id, $invoice->created_by);
        $this->assertSame('Convert me.', $invoice->notes);
        $this->assertNull($invoice->discount_type);
        $this->assertSame('0.0000', $invoice->discount_amount);

        // Synchronized balance caches are initialised on conversion: a
        // converted invoice is Sent and immediately payable.
        $this->assertSame('0.0000', $invoice->amount_paid);
        $this->assertSame('1200.0000', $invoice->amount_due);

        // No-drift: recomputed totals equal the source quotation EXACTLY.
        $quotation->refresh();
        foreach (['subtotal', 'tax_amount', 'total'] as $field) {
            $this->assertSame((string) $quotation->{$field}, (string) $invoice->{$field}, "Expected invoice.$field to equal quotation.$field.");
        }

        // Line snapshots copied verbatim.
        $this->assertSame('sent', $invoice->status->value);
        $this->assertCount(1, $invoice->items);
        $this->assertSame('Original line', $invoice->items->first()->description);
        $this->assertSame('10.0000', $invoice->items->first()->quantity);
        $this->assertSame('120.0000', $invoice->items->first()->unit_price);
        $this->assertNull($invoice->items->first()->tax_rate);
        $this->assertSame('1200.0000', $invoice->items->first()->line_subtotal);
        $this->assertSame('0.0000', $invoice->items->first()->line_tax);
        $this->assertSame('1200.0000', $invoice->items->first()->line_total);

        // Source quotation is finalized.
        $quotation->refresh();
        $this->assertSame(QuotationStatus::Converted, $quotation->status);
    }

    public function test_conversion_recomputes_no_drift_totals_with_discount_and_tax_snapshots(): void
    {
        $user = $this->makeUser('Convert NoDrift User');
        [$business] = $this->provision($user, 'Convert NoDrift Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);
        $this->enableTax();

        $tax = $this->makeTax($business, '20.0000');
        $quotation = $this->storeQuotation($this->makeCustomer($business), [
            'discount_type' => 'percentage',
            'discount_amount' => '10.0000',
            'items' => [
                ['product_id' => null, 'description' => 'A', 'quantity' => '2.0000', 'unit_price' => '100.0000', 'tax_id' => $tax->id],
                ['product_id' => null, 'description' => 'B', 'quantity' => '1.0000', 'unit_price' => '50.0000'],
            ],
        ]);

        $this->convert($quotation)->assertSessionHas('status', __('invoices.converted'));
        $invoice = $this->assertExactlyOneInvoice($business->id, $quotation->id);

        // Recomputed from the locked snapshots: subtotal 250, 20% tax on the
        // taxed line (40), 10% discount (percentage figure stored verbatim as
        // 10.0000) -> 250 + 40 - 25 = 265.0000.
        $quotation->refresh();
        foreach (['subtotal', 'discount_amount', 'tax_amount', 'total'] as $field) {
            $this->assertSame((string) $quotation->{$field}, (string) $invoice->{$field}, "Expected invoice.$field to equal quotation.$field.");
        }
        $this->assertSame('250.0000', $invoice->subtotal);
        $this->assertSame('10.0000', $invoice->discount_amount);
        $this->assertSame('40.0000', $invoice->tax_amount);
        $this->assertSame('265.0000', $invoice->total);

        $first = $invoice->items->first();
        $this->assertSame('200.0000', $first->line_subtotal);
        $this->assertSame('40.0000', $first->line_tax);
        $this->assertSame('240.0000', $first->line_total);
        $this->assertSame('20.0000', $first->tax_rate);
        $second = $invoice->items[1];
        $this->assertSame('0.0000', $second->line_tax);
        $this->assertNull($second->tax_rate);

        // Later tax edits never rewrite the converted document.
        $tax->rate = '99.0000';
        $tax->save();
        $invoice->refresh();
        $this->assertSame('265.0000', $invoice->total);
        $quotation->refresh();
        $this->assertSame('265.0000', $quotation->total);
    }

    // --- Safety: double conversion, rollback, terminal state -----------------

    public function test_a_second_conversion_attempt_fails_safely_with_full_rollback(): void
    {
        $user = $this->makeUser('Convert Double User');
        [$business] = $this->provision($user, 'Convert Double Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $quotation = $this->storeQuotation($this->makeCustomer($business));
        $this->convert($quotation)->assertSessionHas('status', __('invoices.converted'));

        // Second attempt: safe failure (redirect back with the not_convertible
        // error), nothing consumed — matching how web requests surface a
        // ValidationException for invalid document mutations.
        $this->convert($quotation)->assertSessionHasErrors('quotation');

        // Exactly one invoice, one item row, sequence untouched.
        $this->assertExactlyOneInvoice($business->id, $quotation->id);
        $this->assertDatabaseCount('invoice_items', 1);
        $this->assertDatabaseHas('document_number_sequences', ['business_id' => $business->id, 'document_type' => 'invoice', 'last_number' => 1]);
        $this->assertSame(QuotationStatus::Converted, $quotation->refresh()->status);
    }

    public function test_converted_documents_are_terminal_and_immutable(): void
    {
        $user = $this->makeUser('Convert Terminal User');
        [$business] = $this->provision($user, 'Convert Terminal Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $quotation = $this->storeQuotation($this->makeCustomer($business));
        $this->convert($quotation);
        $invoice = $this->assertExactlyOneInvoice($business->id, $quotation->id);

        // The invoice is finalized: GET edit refuses, and even a fully valid
        // update/delete payload is denied (403 from the service's draft-only
        // guard, not from validation).
        $this->get(route('invoices.edit', $invoice))->assertForbidden();
        $this->patch(route('invoices.update', $invoice), $this->invoicePayload($this->makeCustomer($business)))->assertForbidden();
        $this->delete(route('invoices.destroy', $invoice))->assertForbidden();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'deleted_at' => null]);

        // The source quotation is finalized too.
        $this->get(route('quotations.edit', $quotation))->assertForbidden();
        $this->patch(route('quotations.update', $quotation), $this->quotationPayload($this->makeCustomer($business)))->assertForbidden();
        $this->delete(route('quotations.destroy', $quotation))->assertForbidden();
        $this->assertDatabaseHas('quotations', ['id' => $quotation->id, 'deleted_at' => null]);
    }

    public function test_the_converted_documents_invoice_never_changes_when_the_quotation_is_deleted(): void
    {
        $user = $this->makeUser('Convert Survivor User');
        [$business] = $this->provision($user, 'Convert Survivor Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $quotation = $this->storeQuotation($this->makeCustomer($business));
        $this->convert($quotation);
        $invoice = $this->assertExactlyOneInvoice($business->id, $quotation->id);

        $quotationId = $quotation->id;
        $quotation->delete();

        $this->assertSoftDeleted('quotations', ['id' => $quotationId]);
        $invoice->refresh();
        $this->assertSame($quotationId, $invoice->quotation_id);
        $this->assertSame('1200.0000', $invoice->total);
        $this->get(route('invoices.show', $invoice))->assertOk();
    }

    // --- Permissions ----------------------------------------------------------

    public function test_conversion_requires_quotations_view_and_invoices_manage(): void
    {
        $owner = $this->makeUser('Convert Perm Owner');
        [$business] = $this->provision($owner, 'Convert Perm Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($owner, $business);

        $quotation = $this->storeQuotation($this->makeCustomer($business));

        // Roles are business-scoped in this application (slug within a
        // business), so resolve the member's viewer role directly.
        $viewerRole = Role::query()->where('business_id', $business->id)->where('slug', 'viewer')->firstOrFail();
        $member = $this->makeUser('Convert Perm Member');
        $member->memberships()->create(['business_id' => $business->id])->assignRole($viewerRole);
        $this->actIn($member, $business);

        // Base viewer: invoices.view only -> blocked on both required perms.
        $this->assertFalse($viewerRole->hasPermission('quotations.view'));
        $this->assertFalse($viewerRole->hasPermission('invoices.manage'));
        $this->convert($quotation)->assertForbidden();
        $this->assertDatabaseCount('invoices', 0);

        // quotations.view WITHOUT invoices.manage -> still blocked.
        $viewerRole->permissions()->syncWithoutDetaching([Permission::where('name', 'quotations.view')->value('id')]);
        $this->rebuildContext();
        $this->actIn($member, $business);
        $this->convert($quotation)->assertForbidden();
        $this->assertDatabaseCount('invoices', 0);

        // invoices.manage added too -> conversion succeeds.
        $viewerRole->permissions()->syncWithoutDetaching([Permission::where('name', 'invoices.manage')->value('id')]);
        $this->rebuildContext();
        $this->actIn($member, $business);
        $this->convert($quotation)->assertSessionHas('status', __('invoices.converted'));
        $this->assertExactlyOneInvoice($business->id, $quotation->id);
    }

    public function test_conversion_is_blocked_when_the_sales_module_is_disabled(): void
    {
        $user = $this->makeUser('Convert Module User');
        [$business] = $this->provision($user, 'Convert Module Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $quotation = $this->storeQuotation($this->makeCustomer($business));

        $business->modules()->where('module_key', 'sales')->update(['enabled' => false]);
        $this->actIn($user, $business);

        $this->convert($quotation)->assertForbidden();
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(QuotationStatus::Draft, $quotation->refresh()->status);
    }

    // --- Tenancy + source availability ----------------------------------------

    public function test_cross_business_conversion_is_blocked(): void
    {
        $user = $this->makeUser('Convert Tenant User');
        [$businessA] = $this->provision($user, 'Convert Tenant A Co.', 'owner');
        [$businessB] = $this->provision($user, 'Convert Tenant B Co.', 'owner');
        $this->enableSales($businessA);
        $this->enableSales($businessB);

        $this->actIn($user, $businessA);
        $quotation = $this->storeQuotation($this->makeCustomer($businessA));

        $this->actIn($user, $businessB);
        $this->convert($quotation)->assertNotFound();

        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(QuotationStatus::Draft, $quotation->refresh()->status);
    }

    public function test_a_trashed_quotation_cannot_be_converted(): void
    {
        $user = $this->makeUser('Convert Trashed User');
        [$business] = $this->provision($user, 'Convert Trashed Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $quotation = $this->storeQuotation($this->makeCustomer($business));
        $quotation->delete();

        $this->assertSoftDeleted('quotations', ['id' => $quotation->id]);
        $this->convert($quotation)->assertNotFound();

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_past_statuses_are_still_convertible_but_converted_is_terminal(): void
    {
        $user = $this->makeUser('Convert Past User');
        [$business] = $this->provision($user, 'Convert Past Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $rejected = $this->storeQuotation($this->makeCustomer($business), ['status' => 'rejected']);
        $this->convert($rejected)->assertSessionHas('status', __('invoices.converted'));
        $this->assertExactlyOneInvoice($business->id, $rejected->id);
        $this->assertSame(QuotationStatus::Converted, $rejected->refresh()->status);

        $expired = $this->storeQuotation($this->makeCustomer($business), ['status' => 'expired']);
        $this->convert($expired)->assertSessionHas('status', __('invoices.converted'));
        $this->assertSame('INV-000002', Invoice::query()->where('quotation_id', $expired->id)->value('invoice_number'));

        $converted = $this->storeQuotation($this->makeCustomer($business), ['status' => 'accepted']);
        $converted->status = QuotationStatus::Converted;
        $converted->save();
        $this->convert($converted)->assertSessionHasErrors('quotation');
        $this->assertDatabaseCount('invoices', 2);
    }

    // --- Database-level unique backstop --------------------------------------

    public function test_the_database_unique_index_backstops_conversion(): void
    {
        $user = $this->makeUser('Convert Unique User');
        [$business] = $this->provision($user, 'Convert Unique Co.', 'owner');
        $this->enableSales($business);
        $this->actIn($user, $business);

        $quotation = $this->storeQuotation($this->makeCustomer($business));
        $this->convert($quotation);
        $this->assertExactlyOneInvoice($business->id, $quotation->id);

        // Even a raw insert sharing the quotation_id cannot slip through, no
        // matter how the race plays out at the application layer.
        try {
            DB::table('invoices')->insert([
                'business_id' => $business->id,
                'invoice_number' => 'INV-000999',
                'customer_id' => $quotation->customer_id,
                'quotation_id' => $quotation->id,
                'date' => '2026-09-12',
                'status' => 'sent',
                'subtotal' => '1',
                'discount_amount' => '0',
                'tax_amount' => '0',
                'total' => '1',
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected a unique constraint violation on invoices.quotation_id.');
        } catch (UniqueConstraintViolationException|QueryException) {
            $this->assertDatabaseCount('invoices', 1);
            $this->assertDatabaseHas('document_number_sequences', ['business_id' => $business->id, 'document_type' => 'invoice', 'last_number' => 1]);
        }
    }

    // --- Helpers -------------------------------------------------------------
}
