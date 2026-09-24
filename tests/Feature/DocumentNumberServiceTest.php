<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\DocumentNumberSequence;
use App\Models\Role;
use App\Models\User;
use App\Services\BusinessSettings;
use App\Services\DocumentNumberService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DocumentNumberServiceTest extends TestCase
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

    private function makeUser(string $name = 'Numbering User'): User
    {
        return User::create(['name' => $name, 'email' => Str::random(10).'@example.test', 'password' => Hash::make('password')]);
    }

    private function makeBusiness(string $name): Business
    {
        return Business::create(['name' => $name]);
    }

    /**
     * Provision a business with default roles + default modules and attach the
     * given user with the given default-role slug.
     *
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

    private function numbering(): DocumentNumberService
    {
        return app(DocumentNumberService::class);
    }

    private function sessionKey(): string
    {
        return config('business.context.session_key');
    }

    /**
     * Re-create the request-lifecycle context so direct service assertions
     * observe the current acting user and the stored current business.
     */
    private function rebuildContext(): void
    {
        $this->app->forgetScopedInstances();
    }

    /**
     * Acting as the user with the given business selected, then refresh the
     * request-lifecycle context so a freshly resolved DocumentNumberService
     * reads the right business.
     */
    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([$this->sessionKey() => $business->id]);
        $this->rebuildContext();
    }

    // --- Schema + defaults ---------------------------------------------------

    public function test_sequence_schema_holds_per_business_document_type_counters(): void
    {
        $this->assertTrue(Schema::hasTable('document_number_sequences'));
        foreach (['business_id', 'document_type', 'last_number', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('document_number_sequences', $column), "Expected document_number_sequences.$column to exist.");
        }
        $this->assertTrue(Schema::hasIndex('document_number_sequences', ['business_id', 'document_type']));

        $this->assertSame('QUO', config('numbering.prefixes.quotation'));
        $this->assertSame('INV', config('numbering.prefixes.invoice'));
        $this->assertSame('PAY', config('numbering.prefixes.payment'));
        $this->assertSame('EXP', config('numbering.prefixes.expense'));
        $this->assertSame('PO', config('numbering.prefixes.purchase_order'));
        $this->assertSame(6, config('numbering.padding'));

        // The document tables arrive with their own batches: expense records
        // (Batch 17) land in the expenses table, while the remaining future
        // documents have no table yet.
        foreach (['documents', 'purchase_orders'] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Unexpected $table table.");
        }
    }

    // --- Monotonic formatting ------------------------------------------------

    public function test_next_issues_monotonic_zero_padded_numbers_per_type(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Docs Co.', 'owner');
        $this->actIn($user, $business);

        $this->assertSame('QUO-000001', $this->numbering()->next(DocumentType::Quotation));
        $this->assertSame('QUO-000002', $this->numbering()->next(DocumentType::Quotation));
        $this->assertSame('QUO-000003', $this->numbering()->next(DocumentType::Quotation));

        // Independent series per document type within the same business.
        $this->assertSame('INV-000001', $this->numbering()->next(DocumentType::Invoice));
        $this->assertSame('PAY-000001', $this->numbering()->next(DocumentType::Payment));
        $this->assertSame('EXP-000001', $this->numbering()->next(DocumentType::Expense));
        $this->assertSame('PO-000001', $this->numbering()->next(DocumentType::PurchaseOrder));

        $this->assertDatabaseHas('document_number_sequences', [
            'business_id' => $business->id,
            'document_type' => DocumentType::Quotation->value,
            'last_number' => 3,
        ]);
        $this->assertDatabaseHas('document_number_sequences', [
            'business_id' => $business->id,
            'document_type' => DocumentType::Invoice->value,
            'last_number' => 1,
        ]);
    }

    public function test_sequences_are_independent_per_business(): void
    {
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'A Co.', 'owner');
        [$businessB] = $this->provision($user, 'B Co.', 'owner');

        $this->actIn($user, $businessA);
        $this->assertSame('QUO-000001', $this->numbering()->next(DocumentType::Quotation));
        $this->assertSame('QUO-000002', $this->numbering()->next(DocumentType::Quotation));

        $this->actIn($user, $businessB);
        $this->assertSame('QUO-000001', $this->numbering()->next(DocumentType::Quotation));

        // Switching back resumes A's counter exactly where it stopped.
        $this->actIn($user, $businessA);
        $this->assertSame('QUO-000003', $this->numbering()->next(DocumentType::Quotation));

        $this->assertDatabaseHas('document_number_sequences', ['business_id' => $businessA->id, 'document_type' => 'quotation', 'last_number' => 3]);
        $this->assertDatabaseHas('document_number_sequences', ['business_id' => $businessB->id, 'document_type' => 'quotation', 'last_number' => 1]);
        $this->assertDatabaseCount('document_number_sequences', 2);
    }

    public function test_first_use_seeds_a_zeroed_row_lazily(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Seed Co.', 'owner');
        $this->actIn($user, $business);

        $this->assertDatabaseCount('document_number_sequences', 0);

        $this->assertSame('INV-000001', $this->numbering()->next(DocumentType::Invoice));

        $this->assertDatabaseCount('document_number_sequences', 1);
        $this->assertDatabaseHas('document_number_sequences', ['business_id' => $business->id, 'document_type' => 'invoice', 'last_number' => 1]);
    }

    // --- Guards ---------------------------------------------------------------

    public function test_next_requires_a_current_business(): void
    {
        // Guest: no user, no business.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A current business is required');
        $this->numbering()->next(DocumentType::Invoice);
    }

    public function test_next_accepts_only_a_document_type_enum(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Typed Co.', 'owner');
        $this->actIn($user, $business);

        $this->expectException(\TypeError::class);
        $this->numbering()->next('invoice');
    }

    public function test_overflows_are_refused_not_wrapped(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Huge Co.', 'owner');
        $this->actIn($user, $business);

        // Bypass the service to stage an exhausted counter (raw DB state).
        $sequence = new DocumentNumberSequence;
        $sequence->business_id = $business->id;
        $sequence->document_type = DocumentType::Invoice->value;
        $sequence->last_number = PHP_INT_MAX;
        $sequence->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exhausted');
        $this->numbering()->next(DocumentType::Invoice);
    }

    // --- Concurrency constraints ---------------------------------------------

    public function test_unique_business_document_type_constraint_is_enforced_at_the_database(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Dup Co.', 'owner');
        $this->actIn($user, $business);

        $this->numbering()->next(DocumentType::Payment);

        $duplicate = new DocumentNumberSequence;
        $duplicate->business_id = $business->id;
        $duplicate->document_type = DocumentType::Payment->value;
        $duplicate->last_number = 0;

        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    public function test_existing_sequence_rows_are_reused_without_error(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Reuse Co.', 'owner');
        $this->actIn($user, $business);

        $this->assertSame('EXP-000001', $this->numbering()->next(DocumentType::Expense));
        $this->assertSame('EXP-000002', $this->numbering()->next(DocumentType::Expense));

        // The row for this business+type already exists; no seed, no error.
        $this->assertDatabaseCount('document_number_sequences', 1);
    }

    // --- Transaction semantics ----------------------------------------------

    public function test_rolling_back_an_outer_transaction_rolls_back_the_counter(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Atomic Co.', 'owner');
        $this->actIn($user, $business);

        DB::beginTransaction();
        $allocated = $this->numbering()->next(DocumentType::Invoice);
        DB::rollBack();

        $this->assertSame('INV-000001', $allocated);

        // The allocation was not committed: the next number is 1 again.
        $this->assertSame('INV-000001', $this->numbering()->next(DocumentType::Invoice));
        $this->assertDatabaseHas('document_number_sequences', ['business_id' => $business->id, 'document_type' => 'invoice', 'last_number' => 1]);
    }

    // --- Mass assignment + isolation -----------------------------------------

    public function test_sequence_model_is_mass_assignment_guarded(): void
    {
        $this->expectException(MassAssignmentException::class);
        DocumentNumberSequence::query()->create([
            'business_id' => 1,
            'document_type' => 'invoice',
            'last_number' => 0,
        ]);
    }

    public function test_allocations_ignore_a_request_supplied_business_id(): void
    {
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'Trusted Co.', 'owner');
        [$businessB] = $this->provision($user, 'Forged Co.', 'owner');
        $this->actIn($user, $businessA);

        // A fake request payload naming another business is never read: the
        // business always comes from BusinessContext.
        request()->merge(['business_id' => $businessB->id]);
        $this->assertSame('QUO-000001', $this->numbering()->next(DocumentType::Quotation));

        $this->assertDatabaseHas('document_number_sequences', ['business_id' => $businessA->id, 'document_type' => 'quotation']);
        $this->assertDatabaseMissing('document_number_sequences', ['business_id' => $businessB->id]);
    }

    public function test_sequence_rows_cascade_away_with_their_business(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Gone Co.', 'owner');
        $this->actIn($user, $business);

        $this->numbering()->next(DocumentType::PurchaseOrder);
        $this->assertDatabaseCount('document_number_sequences', 1);

        $business->delete();

        $this->assertDatabaseCount('document_number_sequences', 0);
    }

    // --- Settings overrides ---------------------------------------------------

    public function test_prefix_and_padding_overrides_are_honoured_and_cleared(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Styled Co.', 'owner');
        $this->actIn($user, $business);

        app(BusinessSettings::class)->set('numbering.invoice_prefix', 'SALE');
        app(BusinessSettings::class)->set('numbering.padding', 4);

        $this->assertSame('SALE-0001', $this->numbering()->next(DocumentType::Invoice));
        $this->assertSame('SALE-0002', $this->numbering()->next(DocumentType::Invoice));

        // Settings affect the FORMAT only, never the counter itself.
        app(BusinessSettings::class)->set('numbering.padding', 6);
        $this->assertSame('SALE-000003', $this->numbering()->next(DocumentType::Invoice));

        // Clearing the prefix override falls back to the configured default.
        app(BusinessSettings::class)->set('numbering.invoice_prefix', null);
        $this->assertSame('INV-000004', $this->numbering()->next(DocumentType::Invoice));
    }

    public function test_invalid_prefix_override_is_refused_instead_of_misformatted(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Bad Prefix Co.', 'owner');
        $this->actIn($user, $business);

        // Bypass request validation to simulate an invalid stored override.
        app(BusinessSettings::class)->set('numbering.invoice_prefix', 'HAS SPACES');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid document number prefix');
        $this->numbering()->next(DocumentType::Invoice);
    }
}
