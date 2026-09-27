<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\ConsolidationElimination;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\ConsolidatedAccountingReportService;
use App\Services\ConsolidationEliminationService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ConsolidationEliminationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    public function test_balanced_eliminations_create_true_consolidated_totals_without_mutating_company_ledgers(): void
    {
        $user = User::create(['name' => 'Group Owner', 'email' => Str::random(12).'@test.local', 'password' => Hash::make('password')]);
        $a = $this->businessFor($user, 'Parent');
        $b = $this->businessFor($user, 'Subsidiary');

        $this->actingAs($user);
        session([config('business.context.session_key') => $a->id]);
        $this->app->forgetScopedInstances();

        $this->postIntercompanySale($a, 'A', '100.0000');
        $this->postIntercompanyPurchase($b, 'B', '100.0000');

        $reports = app(ConsolidatedAccountingReportService::class);
        $before = $reports->report([$a->id, $b->id], '2026-01-01', '2026-12-31');
        $this->assertSame('100.0000', $before['combined']['total_income']);
        $this->assertSame('100.0000', $before['combined']['total_expenses']);
        $this->assertSame('100.0000', $before['combined']['total_assets']);
        $this->assertSame('100.0000', $before['combined']['total_liabilities']);

        $journalCount = JournalEntry::query()->withoutGlobalScope('business')->count();

        $elimination = app(ConsolidationEliminationService::class)->create([
            'reference' => 'ELIM-2026-001',
            'effective_date' => '2026-12-31',
            'description' => 'Eliminate intercompany sale and balances',
            'lines' => [
                ['statement_type' => 'income', 'debit' => '100', 'credit' => '0', 'memo' => 'Remove group revenue'],
                ['statement_type' => 'expense', 'debit' => '0', 'credit' => '100', 'memo' => 'Remove group expense'],
                ['statement_type' => 'liability', 'debit' => '100', 'credit' => '0', 'memo' => 'Remove payable'],
                ['statement_type' => 'asset', 'debit' => '0', 'credit' => '100', 'memo' => 'Remove receivable'],
            ],
        ], [$a->id, $b->id], $user->id);

        $after = $reports->report([$a->id, $b->id], '2026-01-01', '2026-12-31');
        $this->assertTrue($after['is_consolidated']);
        $this->assertSame('0.0000', $after['consolidated']['total_income']);
        $this->assertSame('0.0000', $after['consolidated']['total_expenses']);
        $this->assertSame('0.0000', $after['consolidated']['total_assets']);
        $this->assertSame('0.0000', $after['consolidated']['total_liabilities']);
        $this->assertSame('0.0000', $after['consolidated']['difference']);

        $this->assertSame($journalCount, JournalEntry::query()->withoutGlobalScope('business')->count());
        $this->assertSame('100.0000', $after['combined']['total_income']);
        $this->assertSame('100.0000', $after['combined']['total_assets']);

        app(ConsolidationEliminationService::class)->reverse($elimination, $user->id);
        $reversed = $reports->report([$a->id, $b->id], '2026-01-01', '2026-12-31');
        $this->assertFalse($reversed['is_consolidated']);
        $this->assertSame('100.0000', $reversed['combined']['total_income']);
        $this->assertSame('reversed', $elimination->fresh()->status);
    }

    public function test_unbalanced_elimination_is_rejected(): void
    {
        $user = User::create(['name' => 'Owner', 'email' => Str::random(12).'@test.local', 'password' => Hash::make('password')]);
        $a = $this->businessFor($user, 'A');
        $b = $this->businessFor($user, 'B');
        $this->actingAs($user);
        session([config('business.context.session_key') => $a->id]);
        $this->app->forgetScopedInstances();

        $this->expectException(ValidationException::class);
        app(ConsolidationEliminationService::class)->create([
            'reference' => 'BAD',
            'effective_date' => '2026-12-31',
            'description' => 'Unbalanced',
            'lines' => [
                ['statement_type' => 'income', 'debit' => '10', 'credit' => '0'],
                ['statement_type' => 'expense', 'debit' => '0', 'credit' => '9'],
            ],
        ], [$a->id, $b->id], $user->id);
    }

    public function test_prior_period_profit_loss_elimination_does_not_leak_into_current_period(): void
    {
        $user = User::create(['name' => 'Owner', 'email' => Str::random(12).'@test.local', 'password' => Hash::make('password')]);
        $a = $this->businessFor($user, 'A');
        $b = $this->businessFor($user, 'B');
        $this->actingAs($user);
        session([config('business.context.session_key') => $a->id]);
        $this->app->forgetScopedInstances();

        app(ConsolidationEliminationService::class)->create([
            'reference' => 'ELIM-2025',
            'effective_date' => '2025-12-31',
            'description' => 'Prior period elimination',
            'lines' => [
                ['statement_type' => 'income', 'debit' => '50', 'credit' => '0'],
                ['statement_type' => 'expense', 'debit' => '0', 'credit' => '50'],
            ],
        ], [$a->id, $b->id], $user->id);

        $report = app(ConsolidatedAccountingReportService::class)->report([$a->id, $b->id], '2026-01-01', '2026-12-31');
        $this->assertSame('0.0000', $report['consolidated']['total_income']);
        $this->assertSame('0.0000', $report['consolidated']['total_expenses']);
    }

    private function businessFor(User $user, string $name): Business
    {
        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        return $business;
    }

    private function account(Business $business, string $code, string $name, string $type): Account
    {
        $account = new Account;
        $account->forceFill(['business_id' => $business->id, 'code' => $code, 'name' => $name, 'type' => $type, 'is_active' => true])->save();

        return $account;
    }

    private function postIntercompanySale(Business $business, string $suffix, string $amount): void
    {
        $receivable = $this->account($business, '1100-'.$suffix, 'Intercompany receivable', 'asset');
        $income = $this->account($business, '4000-'.$suffix, 'Intercompany sales', 'income');
        $entry = new JournalEntry;
        $entry->forceFill(['business_id' => $business->id, 'number' => 'ICS-'.$suffix, 'entry_date' => '2026-06-01', 'status' => 'posted'])->save();
        $entry->lines()->create(['account_id' => $receivable->id, 'debit' => $amount, 'credit' => '0']);
        $entry->lines()->create(['account_id' => $income->id, 'debit' => '0', 'credit' => $amount]);
    }

    private function postIntercompanyPurchase(Business $business, string $suffix, string $amount): void
    {
        $expense = $this->account($business, '5000-'.$suffix, 'Intercompany expense', 'expense');
        $payable = $this->account($business, '2100-'.$suffix, 'Intercompany payable', 'liability');
        $entry = new JournalEntry;
        $entry->forceFill(['business_id' => $business->id, 'number' => 'ICP-'.$suffix, 'entry_date' => '2026-06-01', 'status' => 'posted'])->save();
        $entry->lines()->create(['account_id' => $expense->id, 'debit' => $amount, 'credit' => '0']);
        $entry->lines()->create(['account_id' => $payable->id, 'debit' => '0', 'credit' => $amount]);
    }
}
