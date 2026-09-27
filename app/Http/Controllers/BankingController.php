<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\FinancialAccount;
use App\Models\JournalLine;
use App\Services\BankReconciliationService;
use App\Services\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BankingController extends Controller
{
    public function index(BankReconciliationService $service): View
    {
        $accounts = FinancialAccount::query()->with('account')->orderBy('name')->get();
        $reconciliations = BankReconciliation::query()->with(['financialAccount.account', 'matches'])->latest('statement_date')->get();

        return view('accounting.banking', compact('accounts', 'reconciliations'));
    }

    public function storeAccount(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->where(fn ($q) => $q->where('business_id', $context->currentId())->where('type', 'asset'))],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['cash', 'bank'])],
            'institution' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:120'],
        ]);
        FinancialAccount::create($data + ['is_active' => true]);

        return back()->with('status', __('operations.accounting.financial_account_created'));
    }

    public function storeReconciliation(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'financial_account_id' => ['required', Rule::exists('financial_accounts', 'id')->where('business_id', $context->currentId())],
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
        ]);
        BankReconciliation::create($data + ['status' => 'draft']);

        return back()->with('status', __('operations.accounting.reconciliation_created'));
    }

    public function show(BankReconciliation $bankReconciliation, BankReconciliationService $service): View
    {
        $bankReconciliation->load(['financialAccount.account', 'matches.journalLine.journalEntry']);

        return view('accounting.reconciliation', [
            'reconciliation' => $bankReconciliation,
            'summary' => $service->summary($bankReconciliation),
            'candidates' => $service->candidates($bankReconciliation),
        ]);
    }

    public function match(Request $request, BankReconciliation $bankReconciliation, BankReconciliationService $service): RedirectResponse
    {
        $data = $request->validate(['journal_line_id' => ['required', 'integer']]);
        $line = JournalLine::query()->findOrFail($data['journal_line_id']);
        $service->match($bankReconciliation, $line);

        return back()->with('status', __('operations.accounting.transaction_matched'));
    }

    public function complete(Request $request, BankReconciliation $bankReconciliation, BankReconciliationService $service): RedirectResponse
    {
        $service->complete($bankReconciliation, (int) $request->user()->id);

        return back()->with('status', __('operations.accounting.reconciliation_completed'));
    }

    public function transfer(Request $request, BusinessContext $context, BankReconciliationService $service): RedirectResponse
    {
        $data = $request->validate([
            'from_financial_account_id' => ['required', Rule::exists('financial_accounts', 'id')->where('business_id', $context->currentId())],
            'to_financial_account_id' => ['required', 'different:from_financial_account_id', Rule::exists('financial_accounts', 'id')->where('business_id', $context->currentId())],
            'amount' => ['required', 'numeric', 'gt:0'],
            'entry_date' => ['required', 'date'],
            'memo' => ['nullable', 'string', 'max:2000'],
        ]);
        $from = FinancialAccount::query()->findOrFail($data['from_financial_account_id']);
        $to = FinancialAccount::query()->findOrFail($data['to_financial_account_id']);
        $service->transfer($from, $to, (string) $data['amount'], $data['entry_date'], $data['memo'] ?? null);

        return back()->with('status', __('operations.accounting.transfer_posted'));
    }
}
