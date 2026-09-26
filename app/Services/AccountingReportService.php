<?php

namespace App\Services;

use App\Models\Account;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountingReportService
{
    public function __construct(private readonly BusinessContext $context)
    {
        //
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function trialBalance(?string $to = null): Collection
    {
        $rows = $this->balancesThrough($to);

        return $rows->map(function (array $row): array {
            $raw = Decimal::sub($row['debit'], $row['credit']);

            return $row + [
                'closing_debit' => Decimal::gt($raw, '0') ? $raw : '0.0000',
                'closing_credit' => Decimal::lt($raw, '0') ? Decimal::sub('0', $raw) : '0.0000',
                'balance' => $this->normalBalance($row['type'], $row['debit'], $row['credit']),
            ];
        });
    }

    /**
     * @return array{income:Collection<int,array<string,mixed>>,expenses:Collection<int,array<string,mixed>>,total_income:string,total_expenses:string,net_profit:string}
     */
    public function profitAndLoss(?string $from = null, ?string $to = null): array
    {
        $rows = $this->periodBalances($from, $to)
            ->filter(fn (array $row) => in_array($row['type'], ['income', 'expense'], true))
            ->values();

        $income = $rows->where('type', 'income')->values()->map(function (array $row): array {
            return $row + ['balance' => Decimal::sub($row['credit'], $row['debit'])];
        });

        $expenses = $rows->where('type', 'expense')->values()->map(function (array $row): array {
            return $row + ['balance' => Decimal::sub($row['debit'], $row['credit'])];
        });

        $totalIncome = $this->sumBalances($income);
        $totalExpenses = $this->sumBalances($expenses);

        return [
            'income' => $income,
            'expenses' => $expenses,
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net_profit' => Decimal::sub($totalIncome, $totalExpenses),
        ];
    }

    /**
     * @return array{assets:Collection<int,array<string,mixed>>,liabilities:Collection<int,array<string,mixed>>,equity:Collection<int,array<string,mixed>>,total_assets:string,total_liabilities:string,total_equity:string,current_earnings:string,total_liabilities_equity:string,difference:string}
     */
    public function balanceSheet(?string $to = null): array
    {
        $rows = $this->balancesThrough($to);

        $assets = $rows->where('type', 'asset')->values()->map(
            fn (array $row): array => $row + ['balance' => Decimal::sub($row['debit'], $row['credit'])]
        );
        $liabilities = $rows->where('type', 'liability')->values()->map(
            fn (array $row): array => $row + ['balance' => Decimal::sub($row['credit'], $row['debit'])]
        );
        $equity = $rows->where('type', 'equity')->values()->map(
            fn (array $row): array => $row + ['balance' => Decimal::sub($row['credit'], $row['debit'])]
        );

        $totalAssets = $this->sumBalances($assets);
        $totalLiabilities = $this->sumBalances($liabilities);
        $totalEquity = $this->sumBalances($equity);

        $allTimeProfit = $this->profitAndLoss(null, $to)['net_profit'];
        $liabilitiesAndEquity = Decimal::add(Decimal::add($totalLiabilities, $totalEquity), $allTimeProfit);

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'total_equity' => $totalEquity,
            'current_earnings' => $allTimeProfit,
            'total_liabilities_equity' => $liabilitiesAndEquity,
            'difference' => Decimal::sub($totalAssets, $liabilitiesAndEquity),
        ];
    }

    /**
     * @return array{account:Account,opening_balance:string,lines:Collection<int,array<string,mixed>>,closing_balance:string}
     */
    public function generalLedger(Account $account, ?string $from = null, ?string $to = null): array
    {
        if (! $this->context->isCurrent($account)) {
            throw new RuntimeException('Account does not belong to the current business.');
        }

        $businessId = $this->businessId();
        $openingDebit = '0.0000';
        $openingCredit = '0.0000';

        if ($from !== null) {
            $opening = DB::table('journal_lines')
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_entries.business_id', $businessId)
                ->where('journal_entries.status', 'posted')
                ->where('journal_lines.account_id', $account->id)
                ->whereDate('journal_entries.entry_date', '<', $from)
                ->selectRaw('COALESCE(SUM(journal_lines.debit),0) debit, COALESCE(SUM(journal_lines.credit),0) credit')
                ->first();

            $openingDebit = Decimal::normalize((string) ($opening->debit ?? '0'));
            $openingCredit = Decimal::normalize((string) ($opening->credit ?? '0'));
        }

        $running = $this->normalBalance($account->type, $openingDebit, $openingCredit);

        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', 'posted')
            ->where('journal_lines.account_id', $account->id)
            ->select([
                'journal_entries.id as journal_entry_id',
                'journal_entries.number',
                'journal_entries.entry_date',
                'journal_entries.description',
                'journal_lines.debit',
                'journal_lines.credit',
                'journal_lines.memo',
            ])
            ->orderBy('journal_entries.entry_date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.id');

        if ($from !== null) {
            $query->whereDate('journal_entries.entry_date', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('journal_entries.entry_date', '<=', $to);
        }

        $lines = $query->get()->map(function ($line) use (&$running, $account): array {
            $debit = Decimal::normalize((string) $line->debit);
            $credit = Decimal::normalize((string) $line->credit);
            $movement = $this->normalBalance($account->type, $debit, $credit);
            $running = Decimal::add($running, $movement);

            return [
                'journal_entry_id' => $line->journal_entry_id,
                'number' => $line->number,
                'entry_date' => $line->entry_date,
                'description' => $line->description,
                'memo' => $line->memo,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $running,
            ];
        });

        return [
            'account' => $account,
            'opening_balance' => $this->normalBalance($account->type, $openingDebit, $openingCredit),
            'lines' => $lines,
            'closing_balance' => $running,
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function balancesThrough(?string $to): Collection
    {
        return $this->balanceQuery(null, $to);
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function periodBalances(?string $from, ?string $to): Collection
    {
        return $this->balanceQuery($from, $to);
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function balanceQuery(?string $from, ?string $to): Collection
    {
        $businessId = $this->businessId();

        $query = DB::table('accounts')
            ->leftJoin('journal_lines', 'journal_lines.account_id', '=', 'accounts.id')
            ->leftJoin('journal_entries', function ($join) use ($from, $to): void {
                $join->on('journal_entries.id', '=', 'journal_lines.journal_entry_id')
                    ->where('journal_entries.status', '=', 'posted');

                if ($from !== null) {
                    $join->where('journal_entries.entry_date', '>=', $from);
                }

                if ($to !== null) {
                    $join->where('journal_entries.entry_date', '<=', $to);
                }
            })
            ->where('accounts.business_id', $businessId)
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type')
            ->orderBy('accounts.code')
            ->selectRaw('accounts.id, accounts.code, accounts.name, accounts.type, COALESCE(SUM(CASE WHEN journal_entries.id IS NULL THEN 0 ELSE journal_lines.debit END),0) debit, COALESCE(SUM(CASE WHEN journal_entries.id IS NULL THEN 0 ELSE journal_lines.credit END),0) credit');

        return $query->get()->map(fn ($row): array => [
            'id' => (int) $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'type' => $row->type,
            'debit' => Decimal::normalize((string) $row->debit),
            'credit' => Decimal::normalize((string) $row->credit),
        ]);
    }

    private function normalBalance(string $type, string $debit, string $credit): string
    {
        return in_array($type, ['asset', 'expense'], true)
            ? Decimal::sub($debit, $credit)
            : Decimal::sub($credit, $debit);
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     */
    private function sumBalances(Collection $rows): string
    {
        return $rows->reduce(
            fn (string $carry, array $row): string => Decimal::add($carry, (string) $row['balance']),
            '0.0000',
        );
    }

    private function businessId(): int
    {
        $businessId = $this->context->currentId();

        if ($businessId === null) {
            throw new RuntimeException('A current business is required for accounting reports.');
        }

        return $businessId;
    }
}
