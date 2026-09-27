<?php

namespace App\Services;

use App\Models\AccountingBudget;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BudgetVarianceService
{
    public function __construct(private readonly BusinessContext $context)
    {
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    public function report(AccountingBudget $budget): Collection
    {
        if (!$this->context->isCurrent($budget)) {
            throw new RuntimeException('Budget does not belong to the current business.');
        }

        $businessId = $this->context->currentId();

        return $budget->lines()
            ->with(['account', 'costCenter'])
            ->orderBy('account_id')
            ->get()
            ->map(function ($line) use ($budget, $businessId): array {
                $query = DB::table('journal_lines')
                    ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->where('journal_entries.business_id', $businessId)
                    ->where('journal_entries.status', 'posted')
                    ->whereBetween('journal_entries.entry_date', [$budget->start_date->toDateString(), $budget->end_date->toDateString()])
                    ->where('journal_lines.account_id', $line->account_id);

                if (filled($line->cost_center_id)) {
                    $query->where('journal_lines.cost_center_id', $line->cost_center_id);
                }

                $actualRow = $query->selectRaw('COALESCE(SUM(journal_lines.debit),0) debit, COALESCE(SUM(journal_lines.credit),0) credit')->first();
                $debit = Decimal::normalize((string) ($actualRow->debit ?? 0));
                $credit = Decimal::normalize((string) ($actualRow->credit ?? 0));
                $actual = in_array($line->account->type, ['asset', 'expense'], true)
                    ? Decimal::sub($debit, $credit)
                    : Decimal::sub($credit, $debit);
                $budgetAmount = Decimal::normalize((string) $line->amount);
                $variance = Decimal::sub($actual, $budgetAmount);
                $variancePercent = Decimal::gt($budgetAmount, '0')
                    ? round((float) $variance / (float) $budgetAmount * 100, 2)
                    : null;

                return [
                    'line_id' => $line->id,
                    'account' => $line->account,
                    'cost_center' => $line->costCenter,
                    'budget' => $budgetAmount,
                    'actual' => $actual,
                    'variance' => $variance,
                    'variance_percent' => $variancePercent,
                ];
            });
    }
}
