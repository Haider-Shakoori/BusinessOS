<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Business;
use App\Models\FiscalYearClose;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\AccountingReportService;
use App\Services\FiscalPeriodService;
use App\Services\FiscalYearCloseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FiscalYearCloseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    public function test_year_close_transfers_profit_to_retained_earnings_without_erasing_historical_p_and_l(): void
    {
        $user = User::create([
            'name' => 'Year Close Owner',
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('password'),
        ]);
        $business = Business::create(['name' => 'Year Close Co']);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();

        $cash = Account::create(['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'is_active' => true]);
        $sales = Account::create(['code' => '4000', 'name' => 'Sales', 'type' => 'income', 'is_active' => true]);
        $expense = Account::create(['code' => '5000', 'name' => 'Expense', 'type' => 'expense', 'is_active' => true]);

        $sale = JournalEntry::create([
            'number' => 'SALE-YEAR',
            'entry_date' => '2026-03-01',
            'status' => 'posted',
            'description' => 'Annual sales',
        ]);
        $sale->lines()->create(['account_id' => $cash->id, 'debit' => '1000', 'credit' => '0']);
        $sale->lines()->create(['account_id' => $sales->id, 'debit' => '0', 'credit' => '1000']);

        $cost = JournalEntry::create([
            'number' => 'COST-YEAR',
            'entry_date' => '2026-06-01',
            'status' => 'posted',
            'description' => 'Annual expense',
        ]);
        $cost->lines()->create(['account_id' => $expense->id, 'debit' => '600', 'credit' => '0']);
        $cost->lines()->create(['account_id' => $cash->id, 'debit' => '0', 'credit' => '600']);

        $periods = app(FiscalPeriodService::class);
        $period = $periods->create('FY 2026', '2026-01-01', '2026-12-31');
        $periods->close($period, $user->id, 'Ready for year close');

        $close = app(FiscalYearCloseService::class)->close(
            'FY 2026',
            '2026-01-01',
            '2026-12-31',
            $user->id,
            'Approved year end',
        );

        $this->assertSame('400.0000', (string) $close->net_income);
        $this->assertSame(1, FiscalYearClose::count());

        $close->load('journalEntry.lines.account');
        $lines = $close->journalEntry->lines;
        $this->assertSame('1000.0000', $lines->firstWhere('account.code', '4000')->debit);
        $this->assertSame('600.0000', $lines->firstWhere('account.code', '5000')->credit);
        $this->assertSame('400.0000', $lines->firstWhere('account.code', 'AUTO-RETAINED-EARNINGS')->credit);

        $reports = app(AccountingReportService::class);
        $pAndL = $reports->profitAndLoss('2026-01-01', '2026-12-31');
        $this->assertSame('1000.0000', $pAndL['total_income']);
        $this->assertSame('600.0000', $pAndL['total_expenses']);
        $this->assertSame('400.0000', $pAndL['net_profit']);

        $trial = $reports->trialBalance('2026-12-31');
        $this->assertSame('0.0000', $trial->firstWhere('code', '4000')['balance']);
        $this->assertSame('0.0000', $trial->firstWhere('code', '5000')['balance']);

        $balanceSheet = $reports->balanceSheet('2026-12-31');
        $this->assertSame('0.0000', $balanceSheet['current_earnings']);
        $this->assertSame('400.0000', $balanceSheet['total_equity']);
        $this->assertSame('0.0000', $balanceSheet['difference']);

        $this->expectException(ValidationException::class);
        $periods->reopen($period->fresh());
    }
}
