<?php

namespace App\Services;

use App\Models\BankReconciliation;
use App\Models\FiscalPeriod;
use App\Models\FixedAsset;
use App\Models\FxRevaluation;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FinancialCloseReadinessService
{
    public function assess(string $startDate, string $endDate): array
    {
        $checks = [
            $this->periodCoverage($startDate, $endDate),
            $this->journalIntegrity($startDate, $endDate),
            $this->bankReconciliations($endDate),
            $this->fixedAssetDepreciation($endDate),
            $this->foreignReceivableRevaluation($endDate),
        ];

        return [
            'checks' => $checks,
            'blockers' => collect($checks)->where('severity', 'blocker')->count(),
            'warnings' => collect($checks)->where('severity', 'warning')->count(),
            'passes' => collect($checks)->where('severity', 'pass')->count(),
            'ready' => collect($checks)->where('severity', 'blocker')->isEmpty(),
        ];
    }


    public function assertNoStructuralBlockers(string $startDate, string $endDate): void
    {
        $assessment = $this->assess($startDate, $endDate);
        $blockers = collect($assessment['checks'])->where('severity', 'blocker');

        if ($blockers->isNotEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'start_date' => $blockers->pluck('message')->implode(' '),
            ]);
        }
    }

    private function periodCoverage(string $startDate, string $endDate): array
    {
        $periods = FiscalPeriod::query()
            ->whereDate('end_date', '>=', $startDate)
            ->whereDate('start_date', '<=', $endDate)
            ->orderBy('start_date')
            ->get();

        $cursor = CarbonImmutable::parse($startDate);
        $end = CarbonImmutable::parse($endDate);
        $open = 0;

        foreach ($periods as $period) {
            $periodStart = CarbonImmutable::parse($period->start_date);
            $periodEnd = CarbonImmutable::parse($period->end_date);

            if ($periodStart->gt($cursor)) {
                return $this->check('period_coverage', 'blocker', 'Fiscal periods do not cover the fiscal year without gaps.');
            }

            if ($period->status !== 'closed') {
                $open++;
            }

            if ($periodEnd->gte($cursor)) {
                $cursor = $periodEnd->addDay();
            }
        }

        if ($periods->isEmpty() || $cursor->lte($end)) {
            return $this->check('period_coverage', 'blocker', 'Fiscal periods do not fully cover the fiscal year.');
        }

        if ($open > 0) {
            return $this->check('period_coverage', 'blocker', $open.' fiscal period(s) are still open.');
        }

        return $this->check('period_coverage', 'pass', 'Fiscal periods fully cover the year and are closed.');
    }

    private function journalIntegrity(string $startDate, string $endDate): array
    {
        $unbalanced = JournalEntry::query()
            ->where('status', 'posted')
            ->whereDate('entry_date', '>=', $startDate)
            ->whereDate('entry_date', '<=', $endDate)
            ->whereHas('lines')
            ->get()
            ->filter(function (JournalEntry $entry): bool {
                $debit = $entry->lines->reduce(fn (string $carry, $line): string => Decimal::add($carry, (string) $line->debit), '0.0000');
                $credit = $entry->lines->reduce(fn (string $carry, $line): string => Decimal::add($carry, (string) $line->credit), '0.0000');

                return ! Decimal::eq($debit, $credit);
            })
            ->count();

        $empty = JournalEntry::query()
            ->where('status', 'posted')
            ->whereDate('entry_date', '>=', $startDate)
            ->whereDate('entry_date', '<=', $endDate)
            ->doesntHave('lines')
            ->count();

        if ($unbalanced + $empty > 0) {
            return $this->check('journal_integrity', 'blocker', ($unbalanced + $empty).' posted journal entry/entries are empty or unbalanced.');
        }

        return $this->check('journal_integrity', 'pass', 'Posted journals in the fiscal year are balanced.');
    }

    private function bankReconciliations(string $endDate): array
    {
        $open = BankReconciliation::query()
            ->whereDate('statement_date', '<=', $endDate)
            ->where('status', '!=', 'reconciled')
            ->count();

        return $open > 0
            ? $this->check('bank_reconciliations', 'warning', $open.' bank reconciliation(s) dated through year-end remain open.')
            : $this->check('bank_reconciliations', 'pass', 'No open bank reconciliations are pending through year-end.');
    }

    private function fixedAssetDepreciation(string $endDate): array
    {
        $pending = FixedAsset::query()
            ->where('status', 'active')
            ->whereDate('in_service_date', '<=', $endDate)
            ->where(function ($query) use ($endDate): void {
                $query->whereNull('last_depreciated_through')
                    ->orWhereDate('last_depreciated_through', '<', $endDate);
            })
            ->count();

        return $pending > 0
            ? $this->check('fixed_asset_depreciation', 'warning', $pending.' active fixed asset(s) are not depreciated through year-end.')
            : $this->check('fixed_asset_depreciation', 'pass', 'Active fixed assets are depreciated through year-end.');
    }

    private function foreignReceivableRevaluation(string $endDate): array
    {
        $baseCurrency = app(CurrencyService::class)->baseCurrency();
        $open = Invoice::query()
            ->whereNotIn('status', ['draft', 'paid'])
            ->where('amount_due', '>', 0)
            ->where('currency_code', '!=', $baseCurrency)
            ->pluck('id');

        if ($open->isEmpty()) {
            return $this->check('fx_revaluation', 'pass', 'No open foreign-currency receivables require year-end revaluation.');
        }

        $covered = FxRevaluation::query()
            ->whereIn('invoice_id', $open)
            ->whereDate('revaluation_date', $endDate)
            ->distinct()
            ->count('invoice_id');

        $missing = $open->count() - $covered;

        return $missing > 0
            ? $this->check('fx_revaluation', 'warning', $missing.' open foreign-currency receivable(s) lack a year-end revaluation.')
            : $this->check('fx_revaluation', 'pass', 'Open foreign-currency receivables have year-end revaluations.');
    }

    private function check(string $id, string $severity, string $message): array
    {
        return compact('id', 'severity', 'message');
    }
}
