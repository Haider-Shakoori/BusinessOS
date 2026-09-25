<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ReportService
{
    public const TYPES = ['summary', 'products', 'customers', 'expenses', 'receivables'];

    public function __construct(private readonly CurrencyService $currencies)
    {
        //
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: string, 1: string, 2: string}
     */
    public function dateRange(array $filters): array
    {
        $range = $filters['range'] ?? null;

        if ($range === null && (! empty($filters['date_from']) || ! empty($filters['date_to']))) {
            $range = 'custom';
        }

        $range ??= 'month';
        $today = CarbonImmutable::today();

        return match ($range) {
            'quarter' => ['quarter', $today->startOfQuarter()->toDateString(), $today->toDateString()],
            'year' => ['year', $today->startOfYear()->toDateString(), $today->toDateString()],
            'custom' => [
                'custom',
                CarbonImmutable::parse($filters['date_from'] ?? $today->startOfMonth()->toDateString())->toDateString(),
                CarbonImmutable::parse($filters['date_to'] ?? $today->toDateString())->toDateString(),
            ],
            default => ['month', $today->startOfMonth()->toDateString(), $today->toDateString()],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function run(string $type, string $dateFrom, string $dateTo): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Unsupported report type.');
        }

        return match ($type) {
            'summary' => $this->salesSummary($dateFrom, $dateTo),
            'products' => $this->salesByProduct($dateFrom, $dateTo),
            'customers' => $this->salesByCustomer($dateFrom, $dateTo),
            'expenses' => $this->expenseSummary($dateFrom, $dateTo),
            'receivables' => $this->receivables($dateFrom, $dateTo),
        };
    }

    public function baseCurrency(): string
    {
        return $this->currencies->baseCurrency();
    }

    /**
     * @return array{sales: string, invoice_count: int, average_invoice: string, tax: string, receivables: string}
     */
    private function salesSummary(string $dateFrom, string $dateTo): array
    {
        $invoices = $this->finalizedInvoices($dateFrom, $dateTo)
            ->get(['base_amount', 'tax_amount', 'amount_due', 'exchange_rate']);

        $sales = '0.0000';
        $tax = '0.0000';
        $receivables = '0.0000';

        foreach ($invoices as $invoice) {
            $sales = Decimal::add($sales, (string) $invoice->base_amount);
            $tax = Decimal::add(
                $tax,
                $this->currencies->toBase(
                    (string) $invoice->tax_amount,
                    (string) ($invoice->exchange_rate ?? '1'),
                ),
            );

            if (Decimal::gt((string) $invoice->amount_due, '0')) {
                $receivables = Decimal::add(
                    $receivables,
                    $this->currencies->toBase(
                        (string) $invoice->amount_due,
                        (string) ($invoice->exchange_rate ?? '1'),
                    ),
                );
            }
        }

        $count = $invoices->count();

        return [
            'sales' => $sales,
            'invoice_count' => $count,
            'average_invoice' => $count > 0 ? Decimal::mulDiv($sales, '1', (string) $count) : '0.0000',
            'tax' => $tax,
            'receivables' => $receivables,
        ];
    }

    /**
     * Allocate each finalized invoice's final base amount across its line items
     * in proportion to each line_total. This keeps product reporting reconciled
     * to invoice totals even when a document-level discount is present.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, total: string}
     */
    private function salesByProduct(string $dateFrom, string $dateTo): array
    {
        $rows = [];
        $total = '0.0000';

        $items = InvoiceItem::query()
            ->with(['invoice', 'product'])
            ->whereHas('invoice', function ($query) use ($dateFrom, $dateTo) {
                $query->where('status', '!=', InvoiceStatus::Draft->value)
                    ->whereBetween('date', [$dateFrom, $dateTo]);
            })
            ->orderBy('invoice_id')
            ->orderBy('sort_order')
            ->get();

        foreach ($items as $item) {
            $invoice = $item->invoice;

            if ($invoice === null) {
                continue;
            }

            $key = $item->product_id !== null
                ? 'product:'.$item->product_id
                : 'custom:'.mb_strtolower(trim((string) $item->description));

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'name' => $item->product?->name ?? $item->description,
                    'sku' => $item->product?->sku,
                    'quantity' => '0.0000',
                    'invoice_ids' => [],
                    'sales' => '0.0000',
                ];
            }

            $allocated = Decimal::isZero((string) $invoice->total)
                ? '0.0000'
                : Decimal::mulDiv(
                    (string) $invoice->base_amount,
                    (string) $item->line_total,
                    (string) $invoice->total,
                );

            $rows[$key]['quantity'] = Decimal::add($rows[$key]['quantity'], (string) $item->quantity);
            $rows[$key]['invoice_ids'][(int) $invoice->id] = true;
            $rows[$key]['sales'] = Decimal::add($rows[$key]['sales'], $allocated);
            $total = Decimal::add($total, $allocated);
        }

        $result = collect(array_values($rows))
            ->map(function (array $row): array {
                $row['invoice_count'] = count($row['invoice_ids']);
                unset($row['invoice_ids']);

                return $row;
            })
            ->sort(fn (array $a, array $b): int => bccomp($b['sales'], $a['sales'], Decimal::MONEY_SCALE))
            ->values();

        return ['rows' => $result, 'total' => $total];
    }

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, total: string}
     */
    private function salesByCustomer(string $dateFrom, string $dateTo): array
    {
        $rows = [];
        $total = '0.0000';

        $invoices = $this->finalizedInvoices($dateFrom, $dateTo)
            ->with('customer')
            ->get(['id', 'customer_id', 'base_amount', 'amount_due', 'exchange_rate']);

        foreach ($invoices as $invoice) {
            $key = $invoice->customer_id !== null ? 'customer:'.$invoice->customer_id : 'customer:none';

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'name' => $invoice->customer?->name ?? __('reports.unknown_customer'),
                    'company_name' => $invoice->customer?->company_name,
                    'invoice_count' => 0,
                    'sales' => '0.0000',
                    'receivables' => '0.0000',
                ];
            }

            $sales = (string) $invoice->base_amount;
            $due = $this->currencies->toBase(
                (string) $invoice->amount_due,
                (string) ($invoice->exchange_rate ?? '1'),
            );

            $rows[$key]['invoice_count']++;
            $rows[$key]['sales'] = Decimal::add($rows[$key]['sales'], $sales);
            $rows[$key]['receivables'] = Decimal::add($rows[$key]['receivables'], $due);
            $total = Decimal::add($total, $sales);
        }

        return [
            'rows' => collect(array_values($rows))
                ->sort(fn (array $a, array $b): int => bccomp($b['sales'], $a['sales'], Decimal::MONEY_SCALE))
                ->values(),
            'total' => $total,
        ];
    }

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, total: string}
     */
    private function expenseSummary(string $dateFrom, string $dateTo): array
    {
        $rows = [];
        $total = '0.0000';

        $expenses = Expense::query()
            ->with('category')
            ->whereBetween('expense_date', [$dateFrom, $dateTo])
            ->orderBy('expense_date')
            ->orderBy('id')
            ->get(['id', 'category_id', 'base_amount']);

        foreach ($expenses as $expense) {
            $key = $expense->category_id !== null ? 'category:'.$expense->category_id : 'category:none';

            if (! isset($rows[$key])) {
                $rows[$key] = [
                    'name' => $expense->category?->name ?? __('reports.uncategorized'),
                    'count' => 0,
                    'amount' => '0.0000',
                ];
            }

            $amount = (string) $expense->base_amount;
            $rows[$key]['count']++;
            $rows[$key]['amount'] = Decimal::add($rows[$key]['amount'], $amount);
            $total = Decimal::add($total, $amount);
        }

        return [
            'rows' => collect(array_values($rows))
                ->sort(fn (array $a, array $b): int => bccomp($b['amount'], $a['amount'], Decimal::MONEY_SCALE))
                ->values(),
            'total' => $total,
        ];
    }

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, total: string}
     */
    private function receivables(string $dateFrom, string $dateTo): array
    {
        $rows = collect();
        $total = '0.0000';

        $invoices = Invoice::query()
            ->with('customer')
            ->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::PartiallyPaid->value])
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        foreach ($invoices as $invoice) {
            if (! Decimal::gt((string) $invoice->amount_due, '0')) {
                continue;
            }

            $due = $this->currencies->toBase(
                (string) $invoice->amount_due,
                (string) ($invoice->exchange_rate ?? '1'),
            );

            $rows->push([
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'date' => $invoice->date?->format('Y-m-d'),
                'customer' => $invoice->customer?->name ?? __('reports.unknown_customer'),
                'currency_code' => $invoice->currency_code,
                'invoice_total' => (string) $invoice->base_amount,
                'amount_due' => $due,
            ]);

            $total = Decimal::add($total, $due);
        }

        return ['rows' => $rows, 'total' => $total];
    }

    private function finalizedInvoices(string $dateFrom, string $dateTo)
    {
        return Invoice::query()
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->whereBetween('date', [$dateFrom, $dateTo]);
    }
}
