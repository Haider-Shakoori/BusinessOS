<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\BankReconciliationService;
use App\Services\FiscalPeriodService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BankReconciliationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    private function context(string $name = 'Banking Co'): array
    {
        $user = User::create(['name' => 'Owner', 'email' => Str::random(12).'@example.test', 'password' => Hash::make('password')]);
        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);
        BusinessModule::create(['business_id' => $business->id, 'module_key' => 'accounting', 'enabled' => true]);
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();

        return [$user, $business];
    }

    public function test_reconciliation_uses_posted_gl_and_only_matches_linked_account(): void
    {
        [$user] = $this->context();
        $bankGl = Account::create(['code' => 'BANK-1', 'name' => 'Bank GL', 'type' => 'asset', 'is_active' => true]);
        $otherGl = Account::create(['code' => 'CASH-1', 'name' => 'Cash GL', 'type' => 'asset', 'is_active' => true]);
        $equity = Account::create(['code' => 'EQ-1', 'name' => 'Equity', 'type' => 'equity', 'is_active' => true]);
        $bank = FinancialAccount::create(['account_id' => $bankGl->id, 'name' => 'Main Bank', 'type' => 'bank', 'is_active' => true]);

        $entry = JournalEntry::create(['number' => 'BANK-J1', 'entry_date' => '2026-09-20', 'status' => 'posted']);
        $bankLine = $entry->lines()->create(['account_id' => $bankGl->id, 'debit' => '100', 'credit' => '0']);
        $entry->lines()->create(['account_id' => $equity->id, 'debit' => '0', 'credit' => '100']);

        $other = JournalEntry::create(['number' => 'BANK-J2', 'entry_date' => '2026-09-20', 'status' => 'posted']);
        $otherLine = $other->lines()->create(['account_id' => $otherGl->id, 'debit' => '20', 'credit' => '0']);
        $other->lines()->create(['account_id' => $equity->id, 'debit' => '0', 'credit' => '20']);

        $reconciliation = BankReconciliation::create(['financial_account_id' => $bank->id, 'statement_date' => '2026-09-30', 'statement_balance' => '100', 'status' => 'draft']);
        $service = app(BankReconciliationService::class);

        $this->assertSame('100.0000', $service->summary($reconciliation)['book_balance']);
        $this->assertTrue($service->candidates($reconciliation)->contains('id', $bankLine->id));
        $this->assertFalse($service->candidates($reconciliation)->contains('id', $otherLine->id));

        $this->expectException(ValidationException::class);
        $service->match($reconciliation, $otherLine);
    }

    public function test_balanced_reconciliation_completes_and_transfer_respects_closed_period(): void
    {
        [$user] = $this->context();
        $bankGl = Account::create(['code' => 'BANK-A', 'name' => 'Bank A', 'type' => 'asset', 'is_active' => true]);
        $cashGl = Account::create(['code' => 'CASH-A', 'name' => 'Cash A', 'type' => 'asset', 'is_active' => true]);
        $equity = Account::create(['code' => 'EQ-A', 'name' => 'Equity', 'type' => 'equity', 'is_active' => true]);
        $bank = FinancialAccount::create(['account_id' => $bankGl->id, 'name' => 'Bank', 'type' => 'bank', 'is_active' => true]);
        $cash = FinancialAccount::create(['account_id' => $cashGl->id, 'name' => 'Cash', 'type' => 'cash', 'is_active' => true]);

        $entry = JournalEntry::create(['number' => 'OPEN-BANK', 'entry_date' => '2026-09-10', 'status' => 'posted']);
        $line = $entry->lines()->create(['account_id' => $bankGl->id, 'debit' => '50', 'credit' => '0']);
        $entry->lines()->create(['account_id' => $equity->id, 'debit' => '0', 'credit' => '50']);

        $reconciliation = BankReconciliation::create(['financial_account_id' => $bank->id, 'statement_date' => '2026-09-30', 'statement_balance' => '50', 'status' => 'draft']);
        $service = app(BankReconciliationService::class);
        $service->match($reconciliation, $line);
        $service->complete($reconciliation, $user->id);
        $this->assertSame('reconciled', $reconciliation->fresh()->status);

        $period = app(FiscalPeriodService::class)->create('October', '2026-10-01', '2026-10-31');
        app(FiscalPeriodService::class)->close($period, $user->id);

        $this->expectException(ValidationException::class);
        $service->transfer($bank, $cash, '10', '2026-10-10', 'Locked transfer');
    }

    public function test_banking_account_validation_rejects_foreign_and_non_asset_gl_accounts(): void
    {
        [, $business] = $this->context();
        $income = Account::create(['code' => 'REV', 'name' => 'Revenue', 'type' => 'income', 'is_active' => true]);

        $this->post(route('accounting.banking.accounts.store'), ['account_id' => $income->id, 'name' => 'Invalid', 'type' => 'bank'])
            ->assertSessionHasErrors('account_id');

        $this->assertSame(0, FinancialAccount::count());
    }
}
