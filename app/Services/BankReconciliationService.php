<?php

namespace App\Services;

use App\Models\BankReconciliation;
use App\Models\FinancialAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationService
{
    public function candidates(BankReconciliation $reconciliation): Collection
    {
        $accountId = $reconciliation->financialAccount->account_id;

        return JournalLine::query()
            ->select('journal_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId)
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '<=', $reconciliation->statement_date)
            ->whereDoesntHave('reconciliationMatch')
            ->with('journalEntry')
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_lines.id')
            ->get();
    }

    public function summary(BankReconciliation $reconciliation): array
    {
        $accountId = $reconciliation->financialAccount->account_id;
        $book = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $accountId)
            ->where('journal_entries.status', 'posted')
            ->whereDate('journal_entries.entry_date', '<=', $reconciliation->statement_date)
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) debit, COALESCE(SUM(journal_lines.credit),0) credit')
            ->first();

        $bookBalance = Decimal::sub((string) $book->debit, (string) $book->credit);
        $difference = Decimal::sub((string) $reconciliation->statement_balance, $bookBalance);

        return ['book_balance' => $bookBalance, 'statement_balance' => (string) $reconciliation->statement_balance, 'difference' => $difference];
    }

    public function match(BankReconciliation $reconciliation, JournalLine $line): void
    {
        if ($reconciliation->status === 'reconciled' || $line->account_id !== $reconciliation->financialAccount->account_id) {
            throw ValidationException::withMessages(['journal_line_id' => __('operations.accounting.invalid_reconciliation_match')]);
        }

        $entry = $line->journalEntry;
        if ($entry->status !== 'posted' || $entry->entry_date->gt($reconciliation->statement_date)) {
            throw ValidationException::withMessages(['journal_line_id' => __('operations.accounting.invalid_reconciliation_match')]);
        }

        $reconciliation->matches()->create(['journal_line_id' => $line->id]);
    }

    public function complete(BankReconciliation $reconciliation, int $userId): void
    {
        $summary = $this->summary($reconciliation);
        if (! Decimal::isZero($summary['difference'])) {
            throw ValidationException::withMessages(['statement_balance' => __('operations.accounting.reconciliation_not_balanced')]);
        }

        $reconciliation->update(['status' => 'reconciled', 'reconciled_at' => now(), 'reconciled_by' => $userId]);
    }

    public function transfer(FinancialAccount $from, FinancialAccount $to, string $amount, string $date, ?string $memo = null): JournalEntry
    {
        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_financial_account_id' => __('operations.accounting.transfer_same_account')]);
        }

        app(FiscalPeriodService::class)->assertPostingAllowed($date);
        $amount = Decimal::normalize($amount);

        return DB::transaction(function () use ($from, $to, $amount, $date, $memo): JournalEntry {
            $entry = JournalEntry::create([
                'number' => 'TRF-'.now()->format('YmdHis').'-'.str_pad((string) $from->id, 3, '0', STR_PAD_LEFT),
                'entry_date' => $date,
                'status' => 'posted',
                'description' => $memo ?: 'Financial account transfer',
                'source_type' => FinancialAccount::class,
                'source_id' => $from->id,
            ]);
            $entry->lines()->create(['account_id' => $to->account_id, 'debit' => $amount, 'credit' => '0', 'memo' => $memo]);
            $entry->lines()->create(['account_id' => $from->account_id, 'debit' => '0', 'credit' => $amount, 'memo' => $memo]);

            return $entry->load('lines.account');
        });
    }
}
