<?php

namespace App\Services;

use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\ConsolidationElimination;
use App\Models\FiscalYearClose;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConsolidatedAccountingReportService
{
    public function __construct(
        private readonly BusinessContext $context,
        private readonly CurrencyService $currencies,
    ) {}

    public function availableBusinesses(): Collection
    {
        $user = $this->context->user();

        if ($user === null) {
            return collect();
        }

        return BusinessMembership::query()
            ->where('user_id', $user->id)
            ->with('business')
            ->orderBy('business_id')
            ->get()
            ->filter(fn (BusinessMembership $membership): bool => $membership->hasPermission('accounting.view'))
            ->map(fn (BusinessMembership $membership): Business => $membership->business)
            ->filter()
            ->values();
    }

    public function report(array $businessIds, ?string $from = null, ?string $to = null): array
    {
        $available = $this->availableBusinesses()->keyBy('id');
        $selectedIds = collect($businessIds)
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($selectedIds->isEmpty()) {
            $selectedIds = $available->keys()->map(fn ($id): int => (int) $id)->values();
        }

        if ($selectedIds->diff($available->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'business_ids' => 'One or more selected businesses are not available for accounting consolidation.',
            ]);
        }

        $businesses = $selectedIds->map(fn (int $id): Business => $available->get($id))->values();
        $currencyMap = $businesses->mapWithKeys(
            fn (Business $business): array => [$business->id => $this->currencies->baseCurrency($business)]
        );

        if ($currencyMap->unique()->count() > 1) {
            throw ValidationException::withMessages([
                'business_ids' => 'Selected businesses must share the same base currency before their financial statements can be combined.',
            ]);
        }

        $rows = $businesses->map(function (Business $business) use ($from, $to): array {
            $profitLoss = $this->profitAndLoss($business->id, $from, $to);
            $balanceSheet = $this->balanceSheet($business->id, $to);

            return [
                'business' => $business,
                'profit_loss' => $profitLoss,
                'balance_sheet' => $balanceSheet,
            ];
        });

        $combined = [
            'total_income' => '0.0000',
            'total_expenses' => '0.0000',
            'net_profit' => '0.0000',
            'total_assets' => '0.0000',
            'total_liabilities' => '0.0000',
            'total_equity' => '0.0000',
            'current_earnings' => '0.0000',
            'total_liabilities_equity' => '0.0000',
            'difference' => '0.0000',
        ];

        foreach ($rows as $row) {
            foreach (array_keys($combined) as $key) {
                $source = array_key_exists($key, $row['profit_loss'])
                    ? $row['profit_loss'][$key]
                    : $row['balance_sheet'][$key];
                $combined[$key] = Decimal::add($combined[$key], (string) $source);
            }
        }

        $groupKey = app(ConsolidationEliminationService::class)->groupKey($selectedIds->all());
        $eliminations = ConsolidationElimination::query()
            ->withoutGlobalScope('business')
            ->with(['lines', 'creator'])
            ->where('group_key', $groupKey)
            ->where('status', 'posted')
            ->when($to !== null, fn ($query) => $query->whereDate('effective_date', '<=', $to))
            ->orderBy('effective_date')
            ->get();

        $adjustments = [
            'asset' => '0.0000',
            'liability' => '0.0000',
            'equity' => '0.0000',
            'income' => '0.0000',
            'expense' => '0.0000',
        ];

        foreach ($eliminations as $elimination) {
            foreach ($elimination->lines as $line) {
                $normal = in_array($line->statement_type, ['asset', 'expense'], true)
                    ? Decimal::sub((string) $line->debit, (string) $line->credit)
                    : Decimal::sub((string) $line->credit, (string) $line->debit);
                $adjustments[$line->statement_type] = Decimal::add($adjustments[$line->statement_type], $normal);
            }
        }

        $consolidated = $combined;
        $consolidated['total_income'] = Decimal::add($combined['total_income'], $adjustments['income']);
        $consolidated['total_expenses'] = Decimal::add($combined['total_expenses'], $adjustments['expense']);
        $consolidated['net_profit'] = Decimal::sub($consolidated['total_income'], $consolidated['total_expenses']);
        $consolidated['total_assets'] = Decimal::add($combined['total_assets'], $adjustments['asset']);
        $consolidated['total_liabilities'] = Decimal::add($combined['total_liabilities'], $adjustments['liability']);
        $consolidated['total_equity'] = Decimal::add($combined['total_equity'], $adjustments['equity']);
        $consolidated['current_earnings'] = $consolidated['net_profit'];
        $consolidated['total_liabilities_equity'] = Decimal::add(
            Decimal::add($consolidated['total_liabilities'], $consolidated['total_equity']),
            $consolidated['current_earnings'],
        );
        $consolidated['difference'] = Decimal::sub(
            $consolidated['total_assets'],
            $consolidated['total_liabilities_equity'],
        );

        return [
            'businesses' => $businesses,
            'rows' => $rows,
            'currency' => $currencyMap->first() ?? config('settings.definitions.regional.currency.default', 'AFN'),
            'combined' => $combined,
            'consolidated' => $consolidated,
            'eliminations' => $eliminations,
            'group_key' => $groupKey,
            'is_consolidated' => $eliminations->isNotEmpty(),
            'note' => $eliminations->isNotEmpty()
                ? 'Consolidated statements after posted intercompany eliminations.'
                : 'Combined statements before intercompany eliminations.',
        ];
    }

    private function profitAndLoss(int $businessId, ?string $from, ?string $to): array
    {
        $rows = $this->accountBalances($businessId, $from, $to, true)
            ->filter(fn (array $row): bool => in_array($row['type'], ['income', 'expense'], true));

        $income = $this->sumType($rows, 'income');
        $expenses = $this->sumType($rows, 'expense');

        return [
            'total_income' => $income,
            'total_expenses' => $expenses,
            'net_profit' => Decimal::sub($income, $expenses),
        ];
    }

    private function balanceSheet(int $businessId, ?string $to): array
    {
        $rows = $this->accountBalances($businessId, null, $to, false);
        $assets = $this->sumType($rows, 'asset');
        $liabilities = $this->sumType($rows, 'liability');
        $equity = $this->sumType($rows, 'equity');

        $latestClose = FiscalYearClose::query()
            ->withoutGlobalScope('business')
            ->where('business_id', $businessId)
            ->when($to !== null, fn ($query) => $query->whereDate('end_date', '<=', $to))
            ->latest('end_date')
            ->first();

        $earningsFrom = $latestClose?->end_date?->addDay()->toDateString();
        $currentEarnings = $this->profitAndLoss($businessId, $earningsFrom, $to)['net_profit'];
        $liabilitiesEquity = Decimal::add(Decimal::add($liabilities, $equity), $currentEarnings);

        return [
            'total_assets' => $assets,
            'total_liabilities' => $liabilities,
            'total_equity' => $equity,
            'current_earnings' => $currentEarnings,
            'total_liabilities_equity' => $liabilitiesEquity,
            'difference' => Decimal::sub($assets, $liabilitiesEquity),
        ];
    }

    private function accountBalances(int $businessId, ?string $from, ?string $to, bool $excludeYearClose): Collection
    {
        $movements = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_entries.status', 'posted');

        if ($from !== null) {
            $movements->whereDate('journal_entries.entry_date', '>=', $from);
        }

        if ($to !== null) {
            $movements->whereDate('journal_entries.entry_date', '<=', $to);
        }

        if ($excludeYearClose) {
            $movements->where(function ($query): void {
                $query->whereNull('journal_entries.source_type')
                    ->orWhere('journal_entries.source_type', '!=', FiscalYearClose::class);
            });
        }

        $movements->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, COALESCE(SUM(journal_lines.debit),0) debit, COALESCE(SUM(journal_lines.credit),0) credit');

        return DB::table('accounts')
            ->leftJoinSub($movements, 'movements', 'movements.account_id', '=', 'accounts.id')
            ->where('accounts.business_id', $businessId)
            ->selectRaw('accounts.type, COALESCE(movements.debit,0) debit, COALESCE(movements.credit,0) credit')
            ->get()
            ->map(function ($row): array {
                $debit = Decimal::normalize((string) $row->debit);
                $credit = Decimal::normalize((string) $row->credit);

                return [
                    'type' => $row->type,
                    'balance' => in_array($row->type, ['asset', 'expense'], true)
                        ? Decimal::sub($debit, $credit)
                        : Decimal::sub($credit, $debit),
                ];
            });
    }

    private function sumType(Collection $rows, string $type): string
    {
        return $rows
            ->where('type', $type)
            ->reduce(
                fn (string $carry, array $row): string => Decimal::add($carry, $row['balance']),
                '0.0000',
            );
    }
}
