<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Category;
use App\Models\Expense;
use App\Models\User;
use App\Services\ModuleManager;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Expense lifecycle (Batch 17).
 *
 * An expense belongs to a business (expenses module) and carries an immutable
 * per-business EXP number issued inside the same transaction as the insert by
 * the Batch 13 DocumentNumberService (DocumentType::Expense). Amounts are
 * DECIMAL(16,4) strings normalized through the exact Decimal helper; there is
 * deliberately no currency handling yet (multi-currency is Batch 19).
 *
 * Category selection is tenant-scoped (only active categories of the CURRENT
 * business), the receipt is optional and validated against a strict whitelist
 * (jpg/jpeg/png/webp/pdf, <= 5 MB), stored on the PRIVATE local disk under
 * receipts/{business_id}/ with a generated filename, and served only through an
 * authenticated, permission- and module-gated, tenant-scoped route.
 *
 * Deletes are SOFT: the row, its EXP number, and its receipt file are kept as
 * a financial audit artifact and simply stop appearing in normal queries.
 *
 * Permissions: expenses.view (view), expenses.manage (write). The module must
 * be enabled for the business (module:expenses) before any route is reachable.
 */
class ExpenseTest extends TestCase
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

    private function makeUser(string $name = 'Expense User'): User
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

    private function makeCategory(Business $business, array $attributes = []): Category
    {
        $category = new Category(array_merge([
            'name' => 'Category '.Str::random(5),
        ], $attributes));
        $category->business_id = $business->id;
        $category->save();

        return $category;
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

    private function enableExpenses(Business $business): void
    {
        $this->rebuildContext();
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => 'expenses'],
            ['enabled' => true],
        );
    }

    /**
     * @return array{business: Business, membership: BusinessMembership}
     */
    private function provision(User $user, string $businessName, string $roleSlug = 'admin'): array
    {
        $business = $this->makeBusiness($businessName);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();

        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles[$roleSlug]);

        return [$business, $membership];
    }

    /**
     * A validated, well-formed store payload.
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'category_id' => null,
            'expense_date' => '2026-09-13',
            'amount' => '1250.5000',
            'payment_method' => 'cash',
            'reference' => 'INV-FIXER-9001',
            'vendor' => 'Office Supplies Co.',
            'notes' => 'September stationery and cartridges.',
        ], $overrides);
    }

    private function storeExpense(User $user, Business $business, array $overrides = [], ?UploadedFile $receipt = null): Expense
    {
        $this->actIn($user, $business);

        $parts = $this->payload($overrides);
        if ($receipt !== null) {
            $parts['receipt'] = $receipt;
        }

        $this->post('/expenses', $parts)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('expenses.created'))
            ->assertRedirect();

        $expense = Expense::query()->latest('id')->first();
        $this->assertNotNull($expense);

        return $expense;
    }

    // --- Schema --------------------------------------------------------------

    public function test_expense_schema_is_in_place(): void
    {
        $this->assertTrue(Schema::hasTable('expenses'));

        foreach ([
            'id', 'business_id', 'expense_number', 'category_id', 'expense_date',
            'amount', 'payment_method', 'reference', 'vendor', 'notes',
            'receipt_path', 'created_by', 'deleted_at', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('expenses', $column), "Expected expenses.$column to exist.");
        }

        $this->assertTrue(Schema::hasIndex('expenses', ['business_id', 'expense_number']));
        $this->assertTrue(Schema::hasIndex('expenses', ['business_id', 'expense_date']));
        $this->assertTrue(Schema::hasIndex('expenses', ['business_id', 'category_id']));
    }

    // --- Creation + numbering ------------------------------------------------

    public function test_creating_an_expense_allocates_exp_number_and_redirects(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Expense Co');
        $this->enableExpenses($business);

        $expense = $this->storeExpense($user, $business);

        $this->assertSame('EXP-000001', $expense->expense_number);
        $this->assertSame('2026-09-13', $expense->expense_date->format('Y-m-d'));
        $this->assertSame('1250.5000', $expense->amount);
        $this->assertSame('cash', $expense->payment_method->value);
        $this->assertSame('INV-FIXER-9001', $expense->reference);
        $this->assertSame('Office Supplies Co.', $expense->vendor);
        $this->assertSame($business->id, $expense->business_id);
        $this->assertSame($user->id, $expense->created_by);

        $this->assertDatabaseHas('expenses', ['business_id' => $business->id, 'expense_number' => 'EXP-000001']);
        $this->assertDatabaseHas('document_number_sequences', [
            'business_id' => $business->id,
            'document_type' => 'expense',
            'last_number' => 1,
        ]);

        $this->actIn($user, $business);
        $this->get("/expenses/{$expense->id}")
            ->assertOk()
            ->assertSee('EXP-000001')
            ->assertSee('Office Supplies Co.');
    }

    public function test_supplied_identity_fields_are_never_read_from_the_request(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Guarded Co');
        $this->enableExpenses($business);
        $other = $this->makeBusiness('Other Co');

        $expense = $this->storeExpense($user, $business, [
            'business_id' => $other->id,
            'expense_number' => 'FORGED-999',
            'receipt_path' => 'forged.pdf',
            'created_by' => 999999,
        ]);

        $this->assertSame('EXP-000001', $expense->expense_number);
        $this->assertSame($business->id, $expense->business_id);
        $this->assertSame($user->id, $expense->created_by);
        $this->assertNull($expense->receipt_path);
    }

    public function test_amount_is_normalized_to_four_decimals(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Money Co');
        $this->enableExpenses($business);

        $expense = $this->storeExpense($user, $business, ['amount' => '999.9999']);
        $this->assertSame('999.9999', $expense->amount);

        $expense = $this->storeExpense($user, $business, ['amount' => '0.0049']);
        $this->assertSame('0.0049', $expense->amount);

        $expense = $this->storeExpense($user, $business, ['amount' => '42']);
        $this->assertSame('42.0000', $expense->amount);
    }

    // --- Category scoping -----------------------------------------------------

    public function test_category_must_belong_to_the_current_business(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Cat Co');
        $this->enableExpenses($business);
        $foreign = $this->makeBusiness('Foreign Co');
        $foreignCategory = $this->makeCategory($foreign);

        $this->actIn($user, $business);
        $this->post('/expenses', $this->payload(['category_id' => $foreignCategory->id]))
            ->assertSessionHasErrors(['category_id' => __('expenses.validation.category_id_exists')]);

        $this->assertDatabaseMissing('expenses', ['category_id' => $foreignCategory->id]);
    }

    public function test_category_is_optional_and_current_business_values_are_accepted(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Optional Cat Co');
        $this->enableExpenses($business);
        $category = $this->makeCategory($business);

        // No category at all is fine.
        $this->storeExpense($user, $business);
        $this->assertDatabaseHas('expenses', ['category_id' => null]);

        // A current-business category is fine.
        $expense = $this->storeExpense($user, $business, ['category_id' => $category->id]);
        $this->assertSame($category->id, $expense->category_id);
    }

    public function test_soft_deleted_category_is_not_selectable(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Gone Cat Co');
        $this->enableExpenses($business);
        $category = $this->makeCategory($business);
        $category->delete();

        $this->actIn($user, $business);
        $this->post('/expenses', $this->payload(['category_id' => $category->id]))
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseMissing('expenses', ['category_id' => $category->id]);
    }

    // --- Tenant isolation -----------------------------------------------------

    public function test_cross_business_expenses_are_unreachable(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'Tenant A');
        $this->enableExpenses($businessA);
        $businessB = $this->makeBusiness('Tenant B');
        $rolesB = $businessB->provisionDefaultRoles();
        $businessB->provisionDefaultModules();
        $this->enableExpenses($businessB);
        $user->memberships()->create(['business_id' => $businessB->id])
            ->assignRole($rolesB['admin']);

        $foreign = $this->makeCategory($businessA);
        $expenseB = $this->storeExpense($user, $businessB, ['category_id' => null]);

        // Expense B must never be readable, editable, deletable or servable.
        $this->actIn($user, $businessA);
        $this->get("/expenses/{$expenseB->id}")->assertNotFound();
        $this->get("/expenses/{$expenseB->id}/edit")->assertNotFound();
        $this->get("/expenses/{$expenseB->id}/receipt")->assertNotFound();
        $this->patch("/expenses/{$expenseB->id}", $this->payload(['category_id' => $foreign->id]))->assertNotFound();
        $this->delete("/expenses/{$expenseB->id}")->assertNotFound();

        $this->assertDatabaseHas('expenses', ['id' => $expenseB->id, 'business_id' => $businessB->id]);
        $this->assertDatabaseMissing('expenses', ['id' => $expenseB->id, 'business_id' => $businessA->id]);
    }

    // --- Authorization + module gating ---------------------------------------

    public function test_module_middleware_denies_when_expenses_are_disabled(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Off Co');

        $this->assertFalse(app(ModuleManager::class)->isEnabled('expenses'));

        $this->actIn($user, $business);
        $this->get('/expenses')->assertForbidden();
        $this->get('/expenses/create')->assertForbidden();
    }

    public function test_viewer_can_view_reports_and_receipts_but_not_manage(): void
    {
        Storage::fake('local');
        $admin = $this->makeUser('Expense Admin');
        [$business] = $this->provision($admin, 'Perm Co', 'admin');
        $this->enableExpenses($business);
        $expense = $this->storeExpense($admin, $business, [], UploadedFile::fake()->image('receipt.jpg'));

        $viewer = $this->makeUser('Expense Viewer');
        $membership = $viewer->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($business->roles()->where('slug', 'viewer')->first());

        $this->actIn($viewer, $business);

        $this->get('/expenses')->assertOk();
        $this->get("/expenses/{$expense->id}")->assertOk();
        $this->get('/expenses/report')->assertOk();
        $this->get("/expenses/{$expense->id}/receipt")->assertOk();

        $this->get('/expenses/create')->assertForbidden();
        $this->post('/expenses', $this->payload())->assertForbidden();
        $this->get("/expenses/{$expense->id}/edit")->assertForbidden();
        $this->patch("/expenses/{$expense->id}", $this->payload())->assertForbidden();
        $this->delete("/expenses/{$expense->id}")->assertForbidden();
    }

    // --- Validation -----------------------------------------------------------

    public function test_amount_validation_rejects_bad_inputs(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Bad Amount Co');
        $this->enableExpenses($business);
        $this->actIn($user, $business);

        $bad = [
            'missing' => ['amount' => null],
            'zero' => ['amount' => '0'],
            'negative' => ['amount' => '-5'],
            'non_numeric' => ['amount' => 'abc'],
            'too_many_decimals' => ['amount' => '12.34567'],
            'huge' => ['amount' => '1000000000000'],
        ];

        foreach ($bad as $label => $overrides) {
            $this->post('/expenses', $this->payload($overrides))
                ->assertSessionHasErrors('amount');

            $this->assertDatabaseMissing('expenses', ['reference' => $this->payload($overrides)['reference']]);
            $this->assertDatabaseMissing('document_number_sequences', ['document_type' => 'expense']);
        }
    }

    public function test_payment_method_and_size_limits_are_validated(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Field Co');
        $this->enableExpenses($business);
        $this->actIn($user, $business);

        $this->post('/expenses', $this->payload([
            'payment_method' => 'bitcoin',
            'reference' => str_repeat('r', 101),
            'vendor' => str_repeat('v', 151),
            'notes' => str_repeat('n', 2001),
        ]))->assertSessionHasErrors(['payment_method', 'reference', 'vendor', 'notes']);

        $this->assertDatabaseMissing('expenses', ['business_id' => $business->id]);
    }

    public function test_receipt_type_and_size_are_safe_whitelisted(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Receipt Co');
        $this->enableExpenses($business);
        $this->actIn($user, $business);

        // Executable / script formats are never accepted.
        $this->post('/expenses', $this->payload() + ['receipt' => UploadedFile::fake()->create('expense.sh', 100)])
            ->assertSessionHasErrors('receipt');

        // A PDF above the 5 MB cap is rejected.
        $hugePdf = UploadedFile::fake()->createWithContent('expense.pdf', str_pad("%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n", 6 * 1024 * 1024, 'x'));
        $this->post('/expenses', $this->payload() + ['receipt' => $hugePdf])
            ->assertSessionHasErrors('receipt');

        $this->assertDatabaseMissing('expenses', ['business_id' => $business->id]);
    }

    // --- Receipt storage + serving -------------------------------------------

    public function test_receipt_is_stored_privately_and_served_through_the_tenant_route(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Receipt Store Co');
        $this->enableExpenses($business);

        $expense = $this->storeExpense($user, $business, [], UploadedFile::fake()->image('receipt.jpg'));
        $this->assertNotNull($expense->receipt_path);
        $this->assertStringStartsWith('receipts/'.$business->id.'/', $expense->receipt_path);

        // The generated filename never contains the client name.
        $this->assertStringNotContainsString('receipt', pathinfo($expense->receipt_path, PATHINFO_FILENAME));
        $this->assertTrue(Storage::disk('local')->exists($expense->receipt_path));

        // An unauthenticated request cannot stream the receipt.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->get("/expenses/{$expense->id}/receipt")->assertRedirect(route('login'));

        // The owner streams it inline with the stored content type.
        $this->actIn($user, $business);
        $response = $this->get("/expenses/{$expense->id}/receipt");
        $response->assertOk();
        $this->assertStringContainsString('image/jpeg', $response->headers->get('content-type') ?? '');
    }

    public function test_replacing_a_receipt_deletes_the_previous_file(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Replace Receipt Co');
        $this->enableExpenses($business);

        $expense = $this->storeExpense($user, $business, [], UploadedFile::fake()->image('first.png'));
        $oldPath = $expense->receipt_path;
        $this->assertTrue(Storage::disk('local')->exists($oldPath));

        $this->actIn($user, $business);
        $this->patch("/expenses/{$expense->id}", $this->payload(['amount' => '999.0000']) + [
            'receipt' => UploadedFile::fake()->image('second.jpg'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $expense->refresh();
        $this->assertNotSame($oldPath, $expense->receipt_path);
        $this->assertFalse(Storage::disk('local')->exists($oldPath));
        $this->assertTrue(Storage::disk('local')->exists($expense->receipt_path));
    }

    public function test_removing_a_receipt_deletes_the_file_and_clears_the_path(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Remove Receipt Co');
        $this->enableExpenses($business);

        $expense = $this->storeExpense($user, $business, [], UploadedFile::fake()->image('keep.png'));
        $path = $expense->receipt_path;

        $this->actIn($user, $business);
        $this->patch("/expenses/{$expense->id}", $this->payload() + ['remove_receipt' => '1'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $expense->refresh();
        $this->assertNull($expense->receipt_path);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    // --- Update ---------------------------------------------------------------

    public function test_update_changes_editable_fields_but_never_the_number(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Edit Co');
        $this->enableExpenses($business);
        $category = $this->makeCategory($business);

        $expense = $this->storeExpense($user, $business);
        $this->assertSame('EXP-000001', $expense->expense_number);

        $this->actIn($user, $business);
        $this->patch("/expenses/{$expense->id}", $this->payload([
            'category_id' => $category->id,
            'expense_date' => '2026-09-20',
            'amount' => '88.2500',
            'payment_method' => 'bank_transfer',
            'reference' => 'REF-NEW',
            'vendor' => 'New Vendor LLC',
            'notes' => 'Updated notes.',
        ]))->assertSessionHasNoErrors()->assertSessionHas('status', __('expenses.updated'))->assertRedirect();

        $expense->refresh();
        $this->assertSame('EXP-000001', $expense->expense_number);
        $this->assertSame($category->id, $expense->category_id);
        $this->assertSame('2026-09-20', $expense->expense_date->format('Y-m-d'));
        $this->assertSame('88.2500', $expense->amount);
        $this->assertSame('bank_transfer', $expense->payment_method->value);
        $this->assertSame('REF-NEW', $expense->reference);
        $this->assertSame('New Vendor LLC', $expense->vendor);
        $this->assertSame('Updated notes.', $expense->notes);
    }

    // --- Soft delete ----------------------------------------------------------

    public function test_delete_is_soft_and_keeps_number_and_receipt(): void
    {
        Storage::fake('local');
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Soft Delete Co');
        $this->enableExpenses($business);

        $expense = $this->storeExpense($user, $business, [], UploadedFile::fake()->image('audit.png'));

        $this->actIn($user, $business);
        $this->delete("/expenses/{$expense->id}")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', __('expenses.deleted'))
            ->assertRedirect(route('expenses.index'));

        // Hidden from normal queries/module UIs…
        $this->assertDatabaseMissing('expenses', ['id' => $expense->id, 'deleted_at' => null]);
        $this->get('/expenses')->assertOk()->assertDontSee($expense->expense_number);

        // …but the record, its EXP number, and its receipt survive.
        $this->assertDatabaseHas('expenses', ['id' => $expense->id, 'expense_number' => 'EXP-000001']);
        $this->assertTrue($expense->fresh()->trashed());
        $this->assertTrue(Storage::disk('local')->exists($expense->receipt_path));
    }

    // --- Index filters --------------------------------------------------------

    public function test_index_filters_search_category_and_date_range(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Filter Co');
        $this->enableExpenses($business);
        $rent = $this->makeCategory($business, ['name' => 'Rent']);
        $supplies = $this->makeCategory($business, ['name' => 'Supplies']);

        $this->storeExpense($user, $business, [
            'expense_date' => '2026-09-01',
            'amount' => '1000.0000',
            'category_id' => $rent->id,
            'vendor' => 'Landlord Co',
            'reference' => 'RENT-SEP',
        ]);
        $this->storeExpense($user, $business, [
            'expense_date' => '2026-08-15',
            'amount' => '250.0000',
            'category_id' => $supplies->id,
            'vendor' => 'Paper Co',
            'reference' => 'SUP-AUG',
        ]);

        $this->actIn($user, $business);

        // Search by vendor.
        $this->get('/expenses?search=Landlord')->assertOk()->assertSee('EXP-000001')->assertDontSee('EXP-000002');

        // Category filter.
        $this->get('/expenses?category_id='.$rent->id)->assertOk()->assertSee('EXP-000001')->assertDontSee('EXP-000002');

        // Date range.
        $this->get('/expenses?date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()
            ->assertSee('EXP-000002')
            ->assertDontSee('EXP-000001');

        // Inverted range is rejected.
        $this->get('/expenses?date_from=2026-09-01&date_to=2026-08-01')->assertSessionHasErrors('date_to');
    }

    // --- Report ---------------------------------------------------------------

    public function test_report_respects_filters_and_totals_are_decimal_exact(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Report Co');
        $this->enableExpenses($business);
        $travel = $this->makeCategory($business, ['name' => 'Travel']);

        $this->storeExpense($user, $business, ['expense_date' => '2026-09-01', 'amount' => '100.2500', 'category_id' => $travel->id]);
        $this->storeExpense($user, $business, ['expense_date' => '2026-09-10', 'amount' => '49.7500']);
        $this->storeExpense($user, $business, ['expense_date' => '2026-07-20', 'amount' => '7.0000']);

        $this->actIn($user, $business);

        $this->get('/expenses/report?date_from=2026-09-01&date_to=2026-09-30')
            ->assertOk()
            ->assertSee('150.0000')
            ->assertSee('EXP-000001')
            ->assertDontSee('EXP-000003');

        $this->get('/expenses/report?category_id='.$travel->id)
            ->assertOk()
            ->assertSee('100.2500')
            ->assertDontSee('49.7500');

        $this->get('/expenses/report?date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()
            ->assertSee(__('expenses.report_empty'));

        // An unfiltered report totals every expense exactly.
        $this->get('/expenses/report')->assertOk()->assertSee('157.0000');
    }
}
