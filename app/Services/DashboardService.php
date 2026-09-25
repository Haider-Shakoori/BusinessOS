<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DashboardService
{
    public function __construct(private readonly CurrencyService $currencies)
    {
        //
    }

    /**
     * Build the Batch 22 dashboard read model for the current business.
     *
     * Every model query keeps its BelongsToBusiness global scope. Visibility is
     * decided by DashboardController from both module state and permissions,
     * and hidden datasets are never queried.
     *
     * @param  array<string, bool>  $visibility
     * @return array<string, mixed>
     */
    public function dashboard(string $dateFrom, string $dateTo, array $visibility): array
    {
        return [
            'base_currency' => $this->currencies->baseCurrency(),
            'metrics' => [
                'sales' => ($visibility['sales'] ?? false) ? $this->sales($dateFrom, $dateTo) : null,
                'revenue' => ($visibility['revenue'] ?? false) ? $this->revenue($dateFrom, $dateTo) : null,
                'expenses' => ($visibility['expenses'] ?? false) ? $this->expenses($dateFrom, $dateTo) : null,
                'receivables' => ($visibility['receivables'] ?? false) ? $this->receivables($dateFrom, $dateTo) : null,
            ],
            'top_customers' => ($visibility['top_customers'] ?? false)
                ? $this->topCustomers($dateFrom, $dateTo)
                : collect(),
            'recent_activity' => $this->recentActivity($dateFrom, $dateTo, $visibility),
        ];
    }

    /**
     * Finalized invoices issued in the selected period.
     *
     * @return array{amount: string, count: int}
     */
    private function sales(string $dateFrom, string $dateTo): array
    {
        $query = Invoice::query()
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->whereBetween('date', [$dateFrom, $dateTo]);

        return [
            'amount' => $this->exactSum(clone $query, 'base_amount'),
            'count' => (clone $query)->count(),
        ];
    }

    /**
     * Non-reversed customer payments received in the selected period.
     *
     * @return array{amount: string, count: int}
     */
    private function revenue(string $dateFrom, string $dateTo): array
    {
        $query = Payment::query()
            ->where('party_type', 'customer')
            ->whereNull('reversed_at')
            ->whereBetween('payment_date', [$dateFrom, $dateTo]);

        return [
            'amount' => $this->exactSum(clone $query, 'base_amount'),
            'count' => (clone $query)->count(),
        ];
    }

    /**
     * Expenses recorded in the selected period.
     *
     * @return array{amount: string, count: int}
     */
    private function expenses(string $dateFrom, string $dateTo): array
    {
        $query = Expense::query()->whereBetween('expense_date', [$dateFrom, $dateTo]);

        return [
            'amount' => $this->exactSum(clone $query, 'base_amount'),
            'count' => (clone $query)->count(),
        ];
    }

    /**
     * Outstanding value of invoices issued in the selected period.
     *
     * amount_due is stored in the invoice transaction currency, therefore each
     * balance is converted through the invoice's permanent historical rate.
     *
     * @return array{amount: string, count: int}
     */
    private function receivables(string $dateFrom, string $dateTo): array
    {
        $invoices = Invoice::query()
            ->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::PartiallyPaid->value])
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->get(['amount_due', 'exchange_rate']);

        $total = '0.0000';

        foreach ($invoices as $invoice) {
            $total = Decimal::add(
                $total,
                $this->currencies->toBase(
                    (string) $invoice->amount_due,
                    (string) ($invoice->exchange_rate ?? '1'),
                ),
            );
        }

        return ['amount' => $total, 'count' => $invoices->count()];
    }

    /**
     * Top customers by finalized invoice value for the period.
     *
     * Keeping the reduction in PHP preserves the application's exact decimal
     * money convention on both MySQL and SQLite instead of relying on driver-
     * specific SUM(decimal) return types.
     *
     * @return Collection<int, array{customer_id: int, name: string, company_name: ?string, amount: string, invoice_count: int}>
     */
    private function topCustomers(string $dateFrom, string $dateTo): Collection
    {
        $rows = [];

        $invoices = Invoice::query()
            ->with('customer')
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->whereNotNull('customer_id')
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->get(['id', 'customer_id', 'base_amount']);

        foreach ($invoices as $invoice) {
            $customer = $invoice->customer;

            if ($customer === null) {
                continue;
            }

            $id = (int) $customer->id;

            if (! isset($rows[$id])) {
                $rows[$id] = [
                    'customer_id' => $id,
                    'name' => (string) $customer->name,
                    'company_name' => $customer->company_name,
                    'url' => $customer->trashed() ? null : route('customers.show', $customer),
                    'amount' => '0.0000',
                    'invoice_count' => 0,
                ];
            }

            $rows[$id]['amount'] = Decimal::add($rows[$id]['amount'], (string) $invoice->base_amount);
            $rows[$id]['invoice_count']++;
        }

        return collect(array_values($rows))
            ->sort(fn (array $a, array $b): int => bccomp($b['amount'], $a['amount'], Decimal::MONEY_SCALE))
            ->take(5)
            ->values();
    }

    /**
     * Merge the latest authorized records from enabled modules into one feed.
     *
     * @param  array<string, bool>  $visibility
     * @return Collection<int, array<string, mixed>>
     */
    private function recentActivity(string $dateFrom, string $dateTo, array $visibility): Collection
    {
        $items = collect();

        if ($visibility['recent_invoices'] ?? false) {
            Invoice::query()
                ->with('customer')
                ->whereBetween('date', [$dateFrom, $dateTo])
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->limit(8)
                ->get(['id', 'invoice_number', 'customer_id', 'date', 'status', 'base_amount'])
                ->each(function (Invoice $invoice) use ($items): void {
                    $items->push([
                        'type' => 'invoice',
                        'reference' => $invoice->invoice_number,
                        'party' => $invoice->customer?->name,
                        'date' => $invoice->date?->format('Y-m-d'),
                        'status' => $invoice->status->value,
                        'amount' => (string) $invoice->base_amount,
                        'url' => route('invoices.show', $invoice),
                        'sort' => ($invoice->date?->format('Y-m-d') ?? '').sprintf('-%010d', $invoice->id),
                    ]);
                });
        }

        if ($visibility['recent_payments'] ?? false) {
            Payment::query()
                ->with([
                    'allocations.invoice' => fn ($query) => $query->withTrashed(),
                    'allocations.invoice.customer',
                ])
                ->whereBetween('payment_date', [$dateFrom, $dateTo])
                ->orderByDesc('payment_date')
                ->orderByDesc('id')
                ->limit(8)
                ->get(['id', 'payment_number', 'payment_date', 'base_amount', 'reversed_at'])
                ->each(function (Payment $payment) use ($items): void {
                    $invoice = $payment->allocations->first()?->invoice;

                    $items->push([
                        'type' => 'payment',
                        'reference' => $payment->payment_number,
                        'party' => $invoice?->customer?->name,
                        'date' => $payment->payment_date?->format('Y-m-d'),
                        'status' => $payment->reversed_at === null ? 'received' : 'reversed',
                        'amount' => (string) $payment->base_amount,
                        'url' => route('payments.show', $payment),
                        'sort' => ($payment->payment_date?->format('Y-m-d') ?? '').sprintf('-%010d', $payment->id),
                    ]);
                });
        }

        if ($visibility['recent_expenses'] ?? false) {
            Expense::query()
                ->whereBetween('expense_date', [$dateFrom, $dateTo])
                ->orderByDesc('expense_date')
                ->orderByDesc('id')
                ->limit(8)
                ->get(['id', 'expense_number', 'expense_date', 'vendor', 'base_amount'])
                ->each(function (Expense $expense) use ($items): void {
                    $items->push([
                        'type' => 'expense',
                        'reference' => $expense->expense_number,
                        'party' => $expense->vendor,
                        'date' => $expense->expense_date?->format('Y-m-d'),
                        'status' => 'recorded',
                        'amount' => (string) $expense->base_amount,
                        'url' => route('expenses.show', $expense),
                        'sort' => ($expense->expense_date?->format('Y-m-d') ?? '').sprintf('-%010d', $expense->id),
                    ]);
                });
        }

        return $items->sortByDesc('sort')->take(8)->values();
    }

    private function exactSum(Builder $query, string $column): string
    {
        $total = '0.0000';

        foreach ($query->select($column)->cursor() as $row) {
            $total = Decimal::add($total, (string) ($row->{$column} ?? '0'));
        }

        return $total;
    }
}
