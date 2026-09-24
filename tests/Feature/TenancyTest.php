<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Services\BusinessContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        // Fresh in-memory connection, never migrate:fresh or a developer database.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    private function makeUser(string $name = 'Tenant User'): User
    {
        return User::create(['name' => $name, 'email' => Str::random(10).'@example.test', 'password' => Hash::make('password')]);
    }

    private function makeBusiness(string $name): Business
    {
        return Business::create(['name' => $name]);
    }

    private function sessionKey(): string
    {
        return config('business.context.session_key');
    }

    // --- Verification checklist items 1-5: onboarding flow ------------------

    public function test_authenticated_user_without_business_is_redirected_to_onboarding(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->get('/app')->assertRedirect(route('business.create'));

        $this->actingAs($user)->get(route('business.create'))
            ->assertOk()
            ->assertSee('action="'.e(route('business.store')).'"', false)
            ->assertSee('<input', false);
    }

    public function test_user_can_create_first_business_and_it_becomes_current(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->post(route('business.store'), ['name' => 'Acme Inc.']);
        $response->assertRedirect(route('app.home'));
        $response->assertSessionHas('status');

        $business = Business::where('name', 'Acme Inc.')->first();
        $this->assertNotNull($business);
        $this->assertDatabaseHas('business_memberships', [
            'business_id' => $business->id,
            'user_id' => $user->id,
        ]);
        $this->assertSame($business->id, session($this->sessionKey()));
        // Creation + membership + context run atomically: exactly one owner.
        $this->assertDatabaseCount('business_memberships', 1);

        $this->actingAs($user)->get('/app')
            ->assertOk()
            ->assertSee('Acme Inc.')
            ->assertSee(__('business.current_business'));
    }

    public function test_current_business_persists_across_requests(): void
    {
        $user = $this->makeUser();
        $business = $this->makeBusiness('Persistent Co.');
        $user->businesses()->attach($business);

        $this->actingAs($user)->withSession([$this->sessionKey() => $business->id])->get('/app')->assertOk();
        $this->assertSame($business->id, session($this->sessionKey()));
        $this->actingAs($user)->get('/app')->assertOk();
        $this->assertSame($business->id, session($this->sessionKey()));
    }

    // --- Verification checklist item 6-8: switching -------------------------

    public function test_user_can_switch_between_their_own_businesses(): void
    {
        $user = $this->makeUser();
        $first = $this->makeBusiness('First Co.');
        $second = $this->makeBusiness('Second Co.');
        $user->businesses()->attach([$first->id, $second->id]);

        $this->actingAs($user)->withSession([$this->sessionKey() => $first->id])->get('/app')->assertOk();

        $this->actingAs($user)->post(route('business.switch'), ['business_id' => $second->id])
            ->assertRedirect(route('app.home'))
            ->assertSessionHas('status');

        $this->assertSame($second->id, session($this->sessionKey()));
        $this->assertSame($second->id, app(BusinessContext::class)->currentId());
        $this->get('/app')->assertOk()->assertSee('Second Co.');
    }

    public function test_user_cannot_switch_to_another_users_business_or_missing_ids(): void
    {
        $userA = $this->makeUser('Alice');
        $userB = $this->makeUser('Bob');
        $businessA = $this->makeBusiness('A Co.');
        $businessB = $this->makeBusiness('B Co.');
        $userA->businesses()->attach($businessA);
        $userB->businesses()->attach($businessB);

        foreach ([$businessB->id, 999999, 'not-an-id'] as $forged) {
            $this->actingAs($userA)->post(route('business.switch'), ['business_id' => $forged])
                ->assertForbidden();
        }

        $this->assertNotEquals($businessB->id, session($this->sessionKey()));
        $this->assertSame($businessA->id, app(BusinessContext::class)->currentId());
        $this->assertNotEquals($businessB->id, app(BusinessContext::class)->currentId());
    }

    // --- Verification checklist item 9: stale/forged session ids ------------

    public function test_stale_or_forged_session_business_id_is_rejected_and_safely_resolved(): void
    {
        $userA = $this->makeUser('Alice');
        $userB = $this->makeUser('Bob');
        $businessA = $this->makeBusiness('Alice Co.');
        $businessB = $this->makeBusiness('Bob Co.');
        $userA->businesses()->attach($businessA);
        $userB->businesses()->attach($businessB);

        // User A carries a forged selection of User B's business.
        $this->actingAs($userA)->withSession([$this->sessionKey() => $businessB->id])->get('/app')->assertOk();
        // The forged id was discarded and Alice's own business selected.
        $this->assertSame($businessA->id, session($this->sessionKey()));
        $this->assertSame($businessA->id, app(BusinessContext::class)->currentId());

        // Same outcome when the selected id no longer exists at all.
        $this->actingAs($userA)->withSession([$this->sessionKey() => 424242])->get('/app')->assertOk();
        $this->assertSame($businessA->id, session($this->sessionKey()));
    }

    // --- Verification checklist item 10-12: auth, CSRF, methods -------------

    public function test_unauthenticated_users_cannot_create_or_switch(): void
    {
        $this->get(route('business.create'))->assertRedirect(route('login'));
        $this->post(route('business.store'), ['name' => 'Sneaky'])->assertRedirect(route('login'));
        $this->post(route('business.switch'), ['business_id' => 1])->assertRedirect(route('login'));

        $this->assertDatabaseCount('businesses', 0);
    }

    public function test_switch_requires_post_and_both_state_changing_routes_enforce_csrf(): void
    {
        $user = $this->makeUser();
        $business = $this->makeBusiness('CSRF Co.');
        $user->businesses()->attach($business);

        $this->actingAs($user)->get(route('business.switch'))->assertStatus(405);

        $this->app['env'] = 'local'; // Enable the real CSRF middleware in this test.
        $this->post(route('business.store'), ['name' => 'No Token'])->assertStatus(419);
        $this->post(route('business.switch'), ['business_id' => $business->id])->assertStatus(419);
    }

    // --- Verification checklist item 11: creation can't claim other users ---

    public function test_business_creation_cannot_assign_another_arbitrary_user(): void
    {
        $target = $this->makeUser('Chief');
        $attacker = $this->makeUser('Attacker');

        $this->actingAs($attacker)
            ->post(route('business.store'), ['name' => 'Clean Cleaning', 'user_id' => $target->id, 'owner_id' => $target->id])
            ->assertRedirect(route('app.home'));

        $business = Business::where('name', 'Clean Cleaning')->firstOrFail();
        $this->assertDatabaseHas('business_memberships', ['business_id' => $business->id, 'user_id' => $attacker->id]);
        $this->assertDatabaseMissing('business_memberships', ['business_id' => $business->id, 'user_id' => $target->id]);
        $this->assertSame($business->id, session($this->sessionKey()));
    }

    // --- Verification checklist item 13-14: shell + localization ------------

    public function test_shell_renders_current_business_but_never_foreign_data(): void
    {
        $userA = $this->makeUser('Alice');
        $userB = $this->makeUser('Bob');
        $businessA = $this->makeBusiness('Alice Workspace');
        $businessB = $this->makeBusiness('Bob Workspace');
        $userA->businesses()->attach($businessA);
        $userB->businesses()->attach($businessB);

        $html = $this->actingAs($userA)->withSession([$this->sessionKey() => $businessA->id])->get('/app')->assertOk();

        $html->assertSee('Alice Workspace');
        $html->assertDontSee('Bob Workspace');
    }

    public function test_business_strings_resolve_in_all_supported_locales(): void
    {
        foreach (['en' => 'Create business', 'fa' => 'ایجاد کسب و کار', 'ar' => 'إنشاء نشاط تجاري'] as $locale => $expected) {
            app()->setLocale($locale);
            $this->assertSame($expected, __('business.create'));
        }

        $user = $this->makeUser();
        $business = $this->makeBusiness('مونتاژ کسب و کار');
        $user->businesses()->attach($business);

        foreach ([['fa', 'rtl'], ['ar', 'rtl']] as [$locale, $direction]) {
            $this->actingAs($user)->withSession(['locale' => $locale, $this->sessionKey() => $business->id])
                ->get('/app')->assertOk()
                ->assertSee('dir="'.$direction.'"', false)
                ->assertSee($business->name);
        }
    }

    // --- Verification checklist item 21: mandatory A/B cross-business case --

    public function test_mandatory_cross_business_isolation_between_user_a_and_user_b(): void
    {
        $userA = $this->makeUser('User A');
        $userB = $this->makeUser('User B');
        $businessA = $this->makeBusiness('Business A');
        $businessB = $this->makeBusiness('Business B');
        $userA->businesses()->attach($businessA);
        $userB->businesses()->attach($businessB);

        // User A is locked to Business A.
        $this->actingAs($userA)
            ->get('/app')->assertOk()
            ->assertSee('Business A')->assertDontSee('Business B');
        $this->assertSame($businessA->id, session($this->sessionKey()));

        // A cannot switch to B, even when the request forges B's id.
        $this->post(route('business.switch'), ['business_id' => $businessB->id])->assertForbidden();
        $this->assertSame($businessA->id, session($this->sessionKey()));

        // A forged session id is rejected and safely resolved back to A.
        $this->withSession([$this->sessionKey() => $businessB->id])->post(
            route('business.switch'), ['business_id' => $businessB->id]
        )->assertForbidden();
        $this->withSession([$this->sessionKey() => $businessB->id])
            ->get('/app')->assertOk()
            ->assertSee('Business A')->assertDontSee('Business B');
        $this->assertSame($businessA->id, session($this->sessionKey()));

        // User B is locked to Business B (mirror). A fresh session simulates B
        // signing in, so the auth.session hash guard sees B as a new login.
        $this->flushSession();
        $this->actingAs($userB)
            ->get('/app')->assertOk()
            ->assertSee('Business B')->assertDontSee('Business A');
        $this->assertSame($businessB->id, session($this->sessionKey()));

        // B cannot switch to A, even when the request forges A's id.
        $this->post(route('business.switch'), ['business_id' => $businessA->id])->assertForbidden();
        $this->assertSame($businessB->id, session($this->sessionKey()));

        // A forged session id is rejected and safely resolved back to B.
        $this->withSession([$this->sessionKey() => $businessA->id])
            ->get('/app')->assertOk()
            ->assertSee('Business B')->assertDontSee('Business A');
        $this->assertSame($businessB->id, session($this->sessionKey()));
        $this->actingAs($userB)
            ->post(route('business.switch'), ['business_id' => $businessA->id])->assertForbidden();
        $this->assertSame($businessB->id, session($this->sessionKey()));
    }

    // --- Scope guard: Batch 10 customers schema present, Batch 11+ absent ------

    public function test_batch10_customers_schema_exists_but_no_batch11_scope(): void
    {
        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(Schema::hasTable('permissions'));

        // Batch 8: per-business module enablement exists.
        $this->assertTrue(Schema::hasTable('business_modules'));
        $this->assertTrue(Schema::hasColumn('business_modules', 'business_id'));
        $this->assertTrue(Schema::hasColumn('business_modules', 'module_key'));
        $this->assertTrue(Schema::hasColumn('business_modules', 'enabled'));

        // Batch 9: per-business settings store exists, scoped by business.
        $this->assertTrue(Schema::hasTable('settings'));
        $this->assertTrue(Schema::hasColumn('settings', 'business_id'));
        $this->assertTrue(Schema::hasColumn('settings', 'group'));
        $this->assertTrue(Schema::hasColumn('settings', 'key'));
        $this->assertTrue(Schema::hasColumn('settings', 'value'));
        $this->assertTrue(Schema::hasColumn('settings', 'type'));

        // Batch 10: tenant-owned customer registry with soft deletes.
        $this->assertTrue(Schema::hasTable('customers'));
        foreach (['business_id', 'name', 'company_name', 'email', 'phone', 'address', 'notes', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('customers', $column), "Expected customers.$column to exist.");
        }

        // Batch 18: opening balance is an explicit ledger input, not an aggregate.
        $this->assertTrue(Schema::hasColumn('customers', 'opening_balance'), 'Expected customers.opening_balance to exist.');
        $this->assertTrue(Schema::hasColumn('customers', 'opening_balance_date'), 'Expected customers.opening_balance_date to exist.');

        // No financial aggregates on the registry row — those belong to the
        // invoice/payment batches and must never be denormalised here.
        foreach (['balance', 'credit_limit', 'total_due', 'total_paid'] as $forbidden) {
            $this->assertFalse(Schema::hasColumn('customers', $forbidden), "Unexpected customers.$forbidden column.");
        }

        // No Batch 11 (catalogue) or SaaS/platform-plan scope.
        // Batch 11 adds categories, units, and taxes; products arrives in
        // Batch 12 below.
        $this->assertTrue(Schema::hasTable('categories'));
        foreach (['business_id', 'name', 'description', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('categories', $column), "Expected categories.$column to exist.");
        }

        $this->assertTrue(Schema::hasTable('units'));
        foreach (['business_id', 'name', 'short_name', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('units', $column), "Expected units.$column to exist.");
        }

        $this->assertTrue(Schema::hasTable('taxes'));
        foreach (['business_id', 'name', 'rate', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('taxes', $column), "Expected taxes.$column to exist.");
        }

        // Batch 12: products AND services share ONE registry table.
        $this->assertTrue(Schema::hasTable('products'));
        foreach (['business_id', 'type', 'name', 'sku', 'description', 'category_id', 'unit_id', 'tax_id', 'sale_price', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('products', $column), "Expected products.$column to exist.");
        }

        // No inventory/stock or duplicate product columns on the registry row —
        // those belong to the dedicated inventory batches.
        foreach (['stock', 'quantity', 'cost', 'purchase_price', 'barcode'] as $forbidden) {
            $this->assertFalse(Schema::hasColumn('products', $forbidden), "Unexpected products.$forbidden column.");
        }

        // Batch 13: the per-business numbering state exists — one row per
        // business + document type, uniquely constrained, holding the last
        // allocated number. The other document ENTITY tables (invoice,
        // payment, expense) arrive with their own batches later in the
        // roadmap; quotations ship in Batch 14 below.
        $this->assertTrue(Schema::hasTable('document_number_sequences'));
        foreach (['business_id', 'document_type', 'last_number', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('document_number_sequences', $column), "Expected document_number_sequences.$column to exist.");
        }
        $this->assertTrue(Schema::hasIndex('document_number_sequences', ['business_id', 'document_type']));

        // Batch 14: the quotation document + its item lines exist and are
        // tenanted through business_id (header) / quotation_id (items). The
        // remaining document entities stay out of scope until their batches.
        $this->assertTrue(Schema::hasTable('quotations'));
        $this->assertTrue(Schema::hasTable('quotation_items'));
        foreach (['business_id', 'quotation_number', 'customer_id', 'status', 'subtotal', 'tax_amount', 'total', 'notes', 'terms', 'created_by', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('quotations', $column), "Expected quotations.$column to exist.");
        }
        foreach (['quotation_id', 'product_id', 'quantity', 'unit_price', 'tax_rate', 'line_subtotal', 'line_tax', 'line_total', 'sort_order'] as $column) {
            $this->assertTrue(Schema::hasColumn('quotation_items', $column), "Expected quotation_items.$column to exist.");
        }

        // Batch 15: the invoice document + its item lines exist and are
        // tenanted through business_id (header) / invoice_id (items). The
        // one-to-one quotation reference is enforced with a DB unique
        // constraint, and the business-scoped number is unique.
        $this->assertTrue(Schema::hasTable('invoices'));
        $this->assertTrue(Schema::hasTable('invoice_items'));
        foreach (['business_id', 'invoice_number', 'customer_id', 'quotation_id', 'date', 'status', 'subtotal', 'discount_type', 'discount_amount', 'tax_amount', 'total', 'notes', 'created_by', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('invoices', $column), "Expected invoices.$column to exist.");
        }
        foreach (['invoice_id', 'product_id', 'quantity', 'unit_price', 'tax_rate', 'line_subtotal', 'line_tax', 'line_total', 'sort_order'] as $column) {
            $this->assertTrue(Schema::hasColumn('invoice_items', $column), "Expected invoice_items.$column to exist.");
        }
        $this->assertTrue(Schema::hasIndex('invoices', ['business_id', 'invoice_number']));
        $this->assertTrue(Schema::hasIndex('invoices', ['quotation_id']));

        // Batch 16: payments exist with the polymorphic + allocation model.
        // The payment header is tenanted through business_id; its allocation
        // join table deliberately has NO business_id (tenancy flows through
        // the owning payment/invoice), and the reversed_* columns power the
        // reversal-over-delete decision. The invoices table carries the
        // synchronized amount_paid/amount_due caches.
        $this->assertTrue(Schema::hasTable('payments'));
        $this->assertTrue(Schema::hasTable('payment_allocations'));
        foreach (['business_id', 'payment_number', 'paymentable_type', 'paymentable_id', 'party_type', 'party_id', 'payment_date', 'amount', 'payment_method', 'reference', 'notes', 'reversed_at', 'reversed_by', 'reversal_reason', 'created_by'] as $column) {
            $this->assertTrue(Schema::hasColumn('payments', $column), "Expected payments.$column to exist.");
        }
        foreach (['payment_id', 'invoice_id', 'amount'] as $column) {
            $this->assertTrue(Schema::hasColumn('payment_allocations', $column), "Expected payment_allocations.$column to exist.");
        }
        $this->assertFalse(Schema::hasColumn('payment_allocations', 'business_id'));
        $this->assertTrue(Schema::hasColumn('invoices', 'amount_paid'));
        $this->assertTrue(Schema::hasColumn('invoices', 'amount_due'));
        $this->assertTrue(Schema::hasIndex('payments', ['business_id', 'payment_number']));

        // No other document entity tables or SaaS/platform-plan scope.
        $this->assertFalse(Schema::hasTable('documents'));
        $this->assertFalse(Schema::hasTable('system_settings'));
        $this->assertFalse(Schema::hasColumn('businesses', 'status'));
        $this->assertFalse(Schema::hasColumn('businesses', 'slug'));
        $this->assertFalse(Schema::hasColumn('businesses', 'currency'));
        $this->assertFalse(Schema::hasColumn('business_memberships', 'role'));
        $this->assertFalse(Schema::hasColumn('business_memberships', 'permission'));
    }
}
