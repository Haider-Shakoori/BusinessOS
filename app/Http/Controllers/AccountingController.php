<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntry;
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

    public function storeAccount(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', Rule::unique('accounts', 'code')->where('business_id', $context->currentId())],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'parent_id' => ['nullable', Rule::exists('accounts', 'id')],
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
            'debit_account_id' => ['required', 'different:credit_account_id', Rule::exists('accounts', 'id')],
            'credit_account_id' => ['required', 'different:debit_account_id', Rule::exists('accounts', 'id')],
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
