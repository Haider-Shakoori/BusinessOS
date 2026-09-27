<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\AccountAdjustmentNote;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Support\Decimal;
use Carbon\CarbonImmutable;

class AgingReportService
{
    public function receivables(string $asOf): array
    {
        $rows = [];
        $totals = $this->emptyBuckets();
        $date = CarbonImmutable::parse($asOf);

        $invoices = Invoice::query()
            ->with('customer')
            ->whereIn('status', [InvoiceStatus::Sent->value, InvoiceStatus::PartiallyPaid->value])
            ->whereDate('date', '<=', $asOf)
            ->where('amount_due', '>', 0)
            ->get();

        foreach ($invoices as $invoice) {
            $dueDate = CarbonImmutable::parse(($invoice->due_date ?? $invoice->date)->toDateString());
            $days = max(0, $dueDate->diffInDays($date, false));
            $bucket = $this->bucket($days);
            $amount = Decimal::round(Decimal::mul((string) $invoice->amount_due, (string) ($invoice->exchange_rate ?? '1')));
            $totals[$bucket] = Decimal::add($totals[$bucket], $amount);
            $totals['total'] = Decimal::add($totals['total'], $amount);

            $rows[] = [
                'party' => $invoice->customer?->name ?? '—',
                'document' => $invoice->invoice_number,
                'document_date' => $invoice->date->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'days_overdue' => $days,
                'bucket' => $bucket,
                'amount' => $amount,
            ];
        }

        return ['rows' => collect($rows)->sortByDesc('days_overdue')->values(), 'totals' => $totals];
    }

    public function payables(string $asOf): array
    {
        $rows = [];
        $totals = $this->emptyBuckets();
        $date = CarbonImmutable::parse($asOf);

        $orders = PurchaseOrder::query()
            ->with('supplier')
            ->where('status', 'received')
            ->whereDate('order_date', '<=', $asOf)
            ->orderBy('supplier_id')
            ->orderBy('order_date')
            ->orderBy('id')
            ->get();

        foreach ($orders->groupBy('supplier_id') as $supplierId => $supplierOrders) {
            $remainingPayments = Decimal::normalize((string) Payment::query()
                ->where('party_type', 'supplier')
                ->where('party_id', $supplierId)
                ->whereNull('reversed_at')
                ->whereDate('payment_date', '<=', $asOf)
                ->sum('base_amount'));

            foreach ($supplierOrders as $order) {
                $returns = Decimal::normalize((string) InventoryReturn::query()
                    ->where('type', 'purchase')
                    ->where('source_type', PurchaseOrder::class)
                    ->where('source_id', $order->id)
                    ->where('status', 'completed')
                    ->whereDate('processed_at', '<=', $asOf)
                    ->sum('total'));

                $debitNotes = Decimal::normalize((string) AccountAdjustmentNote::query()
                    ->where('type', 'supplier_debit')
                    ->where('purchase_order_id', $order->id)
                    ->where('status', 'posted')
                    ->whereDate('note_date', '<=', $asOf)
                    ->sum('base_amount'));

                $amount = Decimal::sub(Decimal::sub((string) $order->total, $returns), $debitNotes);
                if (Decimal::gt($remainingPayments, '0')) {
                    $applied = Decimal::gt($remainingPayments, $amount) ? $amount : $remainingPayments;
                    $amount = Decimal::sub($amount, $applied);
                    $remainingPayments = Decimal::sub($remainingPayments, $applied);
                }

                if (Decimal::gt($amount, '0') === false) {
                    continue;
                }

                $dueDate = CarbonImmutable::parse(($order->expected_date ?? $order->order_date)->toDateString());
                $days = max(0, $dueDate->diffInDays($date, false));
                $bucket = $this->bucket($days);
                $totals[$bucket] = Decimal::add($totals[$bucket], $amount);
                $totals['total'] = Decimal::add($totals['total'], $amount);

                $rows[] = [
                    'party' => $order->supplier?->name ?? '—',
                    'document' => $order->number,
                    'document_date' => $order->order_date->toDateString(),
                    'due_date' => $dueDate->toDateString(),
                    'days_overdue' => $days,
                    'bucket' => $bucket,
                    'amount' => $amount,
                ];
            }
        }

        return ['rows' => collect($rows)->sortByDesc('days_overdue')->values(), 'totals' => $totals];
    }

    private function bucket(int $days): string
    {
        return match (true) {
            $days === 0 => 'current',
            $days <= 30 => 'days_1_30',
            $days <= 60 => 'days_31_60',
            $days <= 90 => 'days_61_90',
            default => 'days_90_plus',
        };
    }

    private function emptyBuckets(): array
    {
        return [
            'current' => '0.0000',
            'days_1_30' => '0.0000',
            'days_31_60' => '0.0000',
            'days_61_90' => '0.0000',
            'days_90_plus' => '0.0000',
            'total' => '0.0000',
        ];
    }
}
