<?php

namespace App\Services;

use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\FiscalYearClose;
use App\Models\JournalEntry;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FiscalYearCloseService
{
    public function close(string $name, string $startDate, string $endDate, int $userId, ?string $note = null): FiscalYearClose
    {
        if ($startDate > $endDate) {
            throw ValidationException::withMessages(['end_date' => 'Fiscal year end date must be on or after the start date.']);
        }

        return DB::transaction(function () use ($name, $startDate, $endDate, $userId, $note): FiscalYearClose {
            if (FiscalYearClose::query()
                ->whereDate('start_date', '<=', $endDate)
                ->whereDate('end_date', '>=', $startDate)
                ->lockForUpdate()
                ->exists()) {
                throw ValidationException::withMessages(['start_date' => 'This fiscal year overlaps an already closed fiscal year.']);
            }

            $this->assertClosedPeriodCoverage($startDate, $endDate);

            $retained = Account::firstOrCreate(
                ['code' => 'AUTO-RETAINED-EARNINGS'],
                ['name' => 'Retained Earnings', 'type' => 'equity', 'is_active' => true],
            );

            $balances = DB::table('accounts')
                ->leftJoin('journal_lines', 'journal_lines.account_id', '=', 'accounts.id')
                ->leftJoin('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('accounts.business_id', app(BusinessContext::class)->currentId())
                ->whereIn('accounts.type', ['income', 'expense'])
                ->where('journal_entries.status', 'posted')
                ->whereDate('journal_entries.entry_date', '>=', $startDate)
                ->whereDate('journal_entries.entry_date', '<=', $endDate)
                ->where(function ($query): void {
                    $query->whereNull('journal_entries.source_type')
                        ->orWhere('journal_entries.source_type', '!=', FiscalYearClose::class);
                })
                ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
                ->orderBy('accounts.code')
                ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.type, COALESCE(SUM(journal_lines.debit),0) debit, COALESCE(SUM(journal_lines.credit),0) credit')
                ->get();

            $netIncome = '0.0000';
            foreach ($balances as $row) {
                $normal = $row->type === 'income'
                    ? Decimal::sub((string) $row->credit, (string) $row->debit)
                    : Decimal::sub((string) $row->debit, (string) $row->credit);
                $netIncome = $row->type === 'income'
                    ? Decimal::add($netIncome, $normal)
                    : Decimal::sub($netIncome, $normal);
            }

            $close = FiscalYearClose::create([
                'name' => $name,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'net_income' => $netIncome,
                'retained_earnings_account_id' => $retained->id,
                'closed_at' => now(),
                'closed_by' => $userId,
                'close_note' => $note,
            ]);

            $entry = JournalEntry::create([
                'number' => 'FY-CLOSE-'.str_pad((string) $close->id, 6, '0', STR_PAD_LEFT),
                'entry_date' => $endDate,
                'status' => 'posted',
                'description' => 'Fiscal year close '.$name,
                'source_type' => FiscalYearClose::class,
                'source_id' => $close->id,
            ]);

            $closingDebits = '0.0000';
            $closingCredits = '0.0000';

            foreach ($balances as $row) {
                $debit = Decimal::normalize((string) $row->debit);
                $credit = Decimal::normalize((string) $row->credit);
                $netDebit = Decimal::sub($debit, $credit);

                if (Decimal::gt($netDebit, '0')) {
                    $entry->lines()->create([
                        'account_id' => $row->id,
                        'debit' => '0',
                        'credit' => $netDebit,
                        'memo' => 'Close '.$row->code,
                    ]);
                    $closingCredits = Decimal::add($closingCredits, $netDebit);
                } elseif (Decimal::lt($netDebit, '0')) {
                    $amount = Decimal::sub('0', $netDebit);
                    $entry->lines()->create([
                        'account_id' => $row->id,
                        'debit' => $amount,
                        'credit' => '0',
                        'memo' => 'Close '.$row->code,
                    ]);
                    $closingDebits = Decimal::add($closingDebits, $amount);
                }
            }

            $difference = Decimal::sub($closingDebits, $closingCredits);
            if (Decimal::gt($difference, '0')) {
                $entry->lines()->create([
                    'account_id' => $retained->id,
                    'debit' => '0',
                    'credit' => $difference,
                    'memo' => 'Transfer current earnings',
                ]);
            } elseif (Decimal::lt($difference, '0')) {
                $entry->lines()->create([
                    'account_id' => $retained->id,
                    'debit' => Decimal::sub('0', $difference),
                    'credit' => '0',
                    'memo' => 'Transfer current loss',
                ]);
            }

            $close->forceFill(['journal_entry_id' => $entry->id])->save();

            return $close->fresh(['journalEntry.lines.account', 'retainedEarningsAccount', 'closedBy']);
        });
    }

    private function assertClosedPeriodCoverage(string $startDate, string $endDate): void
    {
        $periods = FiscalPeriod::query()
            ->whereDate('end_date', '>=', $startDate)
            ->whereDate('start_date', '<=', $endDate)
            ->orderBy('start_date')
            ->get();

        if ($periods->isEmpty()) {
            throw ValidationException::withMessages(['start_date' => 'Create and close fiscal periods covering the entire fiscal year first.']);
        }

        $cursor = CarbonImmutable::parse($startDate);
        $end = CarbonImmutable::parse($endDate);

        foreach ($periods as $period) {
            if ($period->status !== 'closed') {
                throw ValidationException::withMessages(['start_date' => 'Every fiscal period in the fiscal year must be closed first.']);
            }

            $periodStart = CarbonImmutable::parse($period->start_date);
            $periodEnd = CarbonImmutable::parse($period->end_date);

            if ($periodStart->gt($cursor)) {
                throw ValidationException::withMessages(['start_date' => 'Fiscal periods must cover the fiscal year without gaps.']);
            }

            if ($periodEnd->gte($cursor)) {
                $cursor = $periodEnd->addDay();
            }

            if ($cursor->gt($end)) {
                return;
            }
        }

        throw ValidationException::withMessages(['end_date' => 'Fiscal periods do not fully cover the fiscal year.']);
    }
}
