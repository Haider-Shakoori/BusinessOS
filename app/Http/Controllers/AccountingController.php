<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountingBudget;
use App\Models\CostCenter;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Services\AccountingReportService;
use App\Services\BudgetVarianceService;
use App\Services\BusinessContext;
use App\Services\FiscalPeriodService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountingController extends Controller
{
    public function index(): View
    {
        return view('accounting.index', [
            'accounts' => Account::query()->orderBy('code')->get(),
            'entries' => JournalEntry::query()->with('lines.account')->latest('entry_date')->latest('id')->limit(50)->get(),
            'fiscalPeriods' => FiscalPeriod::query()->latest('start_date')->get(),
            'costCenters' => CostCenter::query()->orderBy('code')->get(),
            'budgets' => AccountingBudget::query()->with(['lines.account', 'lines.costCenter'])->latest('start_date')->get(),
        ]);
    }

    public function reports(
        Request $request,
        BusinessContext $context,
        AccountingReportService $reports,
    ): View {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('business_id', $context->currentId()),
            ],
        ]);

        $account = isset($data['account_id'])
            ? Account::query()->findOrFail($data['account_id'])
            : Account::query()->orderBy('code')->first();

        return view('accounting.reports', [
            'accounts' => Account::query()->orderBy('code')->get(),
            'trialBalance' => $reports->trialBalance($data['date_to'] ?? null),
            'profitLoss' => $reports->profitAndLoss($data['date_from'] ?? null, $data['date_to'] ?? null),
            'balanceSheet' => $reports->balanceSheet($data['date_to'] ?? null),
            'ledger' => $account ? $reports->generalLedger($account, $data['date_from'] ?? null, $data['date_to'] ?? null) : null,
            'costCenterSummary' => $reports->costCenterSummary($data['date_from'] ?? null, $data['date_to'] ?? null),
            'dateFrom' => $data['date_from'] ?? null,
            'dateTo' => $data['date_to'] ?? null,
            'accountId' => $account?->id,
        ]);
    }

    public function storeAccount(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('accounts', 'code')->where('business_id', $context->currentId())],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'parent_id' => ['nullable', Rule::exists('accounts', 'id')->where('business_id', $context->currentId())],
        ]);

        Account::create($data + ['is_active' => true]);

        return back()->with('status', __('operations.accounting.account_created'));
    }

    public function storeJournal(Request $request, BusinessContext $context, FiscalPeriodService $periods): RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:80', Rule::unique('journal_entries', 'number')->where('business_id', $context->currentId())],
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'debit_account_id' => ['required', 'different:credit_account_id', Rule::exists('accounts', 'id')->where('business_id', $context->currentId())],
            'credit_account_id' => ['required', 'different:debit_account_id', Rule::exists('accounts', 'id')->where('business_id', $context->currentId())],
            'amount' => ['required', 'numeric', 'gt:0'],
            'cost_center_id' => ['nullable', Rule::exists('cost_centers', 'id')->where('business_id', $context->currentId())],
        ]);

        $periods->assertPostingAllowed($data['entry_date']);

        DB::transaction(function () use ($data): void {
            $entry = JournalEntry::create([
                'number' => $data['number'],
                'entry_date' => $data['entry_date'],
                'status' => 'posted',
                'description' => $data['description'] ?? null,
            ]);

            $entry->lines()->create([
                'account_id' => $data['debit_account_id'],
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'debit' => $data['amount'],
                'credit' => 0,
            ]);

            $entry->lines()->create([
                'account_id' => $data['credit_account_id'],
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'debit' => 0,
                'credit' => $data['amount'],
            ]);
        });

        return back()->with('status', __('operations.accounting.journal_created'));
    }

    public function storeBudget(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('business_id', $context->currentId())],
            'cost_center_id' => ['nullable', Rule::exists('cost_centers', 'id')->where('business_id', $context->currentId())],
            'amount' => ['required', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($data): void {
            $budget = AccountingBudget::create([
                'name' => $data['name'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'status' => 'draft',
            ]);
            $budget->lines()->create([
                'account_id' => $data['account_id'],
                'cost_center_id' => $data['cost_center_id'] ?? null,
                'amount' => $data['amount'],
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return back()->with('status', __('operations.accounting.budget_created'));
    }

    public function storeBudgetLine(Request $request, AccountingBudget $accountingBudget, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('business_id', $context->currentId())],
            'cost_center_id' => ['nullable', Rule::exists('cost_centers', 'id')->where('business_id', $context->currentId())],
            'amount' => ['required', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $accountingBudget->lines()->updateOrCreate([
            'account_id' => $data['account_id'],
            'cost_center_id' => $data['cost_center_id'] ?? null,
        ], [
            'amount' => $data['amount'],
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('status', __('operations.accounting.budget_line_saved'));
    }

    public function budgetReport(AccountingBudget $accountingBudget, BudgetVarianceService $variances): View
    {
        return view('accounting.budget-report', [
            'budget' => $accountingBudget,
            'rows' => $variances->report($accountingBudget),
        ]);
    }

    public function storeCostCenter(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('cost_centers', 'code')->where('business_id', $context->currentId())],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        CostCenter::create($data + ['is_active' => true]);

        return back()->with('status', __('operations.accounting.cost_center_created'));
    }

    public function storeFiscalPeriod(Request $request, FiscalPeriodService $periods): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);

        $periods->create($data['name'], $data['start_date'], $data['end_date']);

        return back()->with('status', __('operations.accounting.fiscal_period_created'));
    }

    public function closeFiscalPeriod(Request $request, FiscalPeriod $fiscalPeriod, FiscalPeriodService $periods): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $periods->close($fiscalPeriod, (int) $request->user()->id, $data['note'] ?? null);

        return back()->with('status', __('operations.accounting.fiscal_period_closed'));
    }

    public function reopenFiscalPeriod(FiscalPeriod $fiscalPeriod, FiscalPeriodService $periods): RedirectResponse
    {
        $periods->reopen($fiscalPeriod);

        return back()->with('status', __('operations.accounting.fiscal_period_reopened'));
    }
}
