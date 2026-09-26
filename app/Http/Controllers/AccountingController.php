<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\AccountingReportService;
use App\Services\BusinessContext;
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

    public function storeJournal(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:80', Rule::unique('journal_entries', 'number')->where('business_id', $context->currentId())],
            'entry_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
            'debit_account_id' => ['required', 'different:credit_account_id', Rule::exists('accounts', 'id')->where('business_id', $context->currentId())],
            'credit_account_id' => ['required', 'different:debit_account_id', Rule::exists('accounts', 'id')->where('business_id', $context->currentId())],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        DB::transaction(function () use ($data): void {
            $entry = JournalEntry::create([
                'number' => $data['number'],
                'entry_date' => $data['entry_date'],
                'status' => 'posted',
                'description' => $data['description'] ?? null,
            ]);

            $entry->lines()->create([
                'account_id' => $data['debit_account_id'],
                'debit' => $data['amount'],
                'credit' => 0,
            ]);

            $entry->lines()->create([
                'account_id' => $data['credit_account_id'],
                'debit' => 0,
                'credit' => $data['amount'],
            ]);
        });

        return back()->with('status', __('operations.accounting.journal_created'));
    }
}
