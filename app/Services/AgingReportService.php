<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\AccountAdjustmentNote;
use App\Models\GoodsReceipt;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
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
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->whereDate('date', '<=', $asOf)
            ->get();

        foreach ($invoices as $invoice) {
            $paid = PaymentAllocation::query()
                ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
                ->where('payment_allocations.invoice_id', $invoice->id)
                ->whereDate('payments.payment_date', '<=', $asOf)
                ->where(function ($query) use ($asOf): void {
                    $query->whereNull('payments.reversed_at')
                        ->orWhereDate('payments.reversed_at', '>', $asOf);
                })
                ->sum('payment_allocations.amount');

            $credits = AccountAdjustmentNote::query()
                ->where('type', 'customer_credit')
                ->where('invoice_id', $invoice->id)
                ->where('status', 'posted')
                ->whereDate('note_date', '<=', $asOf)
                ->sum('amount');

            $foreignDue = Decimal::sub(Decimal::sub((string) $invoice->total, (string) $paid), (string) $credits);
            if (Decimal::gt($foreignDue, '0') === false) {
                continue;
            }

            $dueDate = CarbonImmutable::parse(($invoice->due_date ?? $invoice->date)->toDateString());
            $days = max(0, $dueDate->diffInDays($date, false));
            $bucket = $this->bucket($days);
            $amount = Decimal::round(Decimal::mul($foreignDue, (string) ($invoice->exchange_rate ?? '1')));
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
        $documents = collect();

        $legacyOrders = PurchaseOrder::query()
            ->with('supplier')
            ->where('ap_recognition', 'receipt')
            ->whereIn('status', ['partially_received', 'received'])
            ->whereDate('order_date', '<=', $asOf)
            ->orderBy('supplier_id')
            ->orderBy('order_date')
            ->orderBy('id')
            ->get();

        foreach ($legacyOrders as $order) {
            $receiptQuery = GoodsReceipt::query()
                ->where('purchase_order_id', $order->id)
                ->where('status', 'posted')
                ->whereDate('receipt_date', '<=', $asOf);

            $hasReceipts = (clone $receiptQuery)->exists();
            $purchased = $hasReceipts
                ? Decimal::normalize((string) (clone $receiptQuery)->sum('total'))
                : ($order->status === 'received' ? Decimal::normalize((string) $order->total) : '0.0000');

            if (! Decimal::gt($purchased, '0')) {
                continue;
            }

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

            $amount = Decimal::sub(Decimal::sub($purchased, $returns), $debitNotes);

            if (! Decimal::gt($amount, '0')) {
                continue;
            }

            $documentDate = $order->order_date->toDateString();
            $dueDate = ($order->expected_date ?? $order->order_date)->toDateString();

            $documents->push([
                'supplier_id' => $order->supplier_id,
                'party' => $order->supplier?->name ?? '—',
                'document' => $order->number,
                'document_date' => $documentDate,
                'due_date' => $dueDate,
                'amount' => $amount,
                'sort_date' => $documentDate,
                'sort_id' => $order->id,
            ]);
        }

        $supplierInvoices = SupplierInvoice::query()
            ->with('supplier')
            ->where('status', 'approved')
            ->whereDate('approved_at', '<=', $asOf)
            ->whereDate('invoice_date', '<=', $asOf)
            ->whereHas('purchaseOrder', fn ($query) => $query->where('ap_recognition', 'invoice'))
            ->orderBy('supplier_id')
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();

        foreach ($supplierInvoices as $invoice) {
            $documentDate = $invoice->invoice_date->toDateString();
            $dueDate = ($invoice->due_date ?? $invoice->invoice_date)->toDateString();

            $documents->push([
                'supplier_id' => $invoice->supplier_id,
                'party' => $invoice->supplier?->name ?? '—',
                'document' => $invoice->number,
                'document_date' => $documentDate,
                'due_date' => $dueDate,
                'amount' => Decimal::normalize((string) $invoice->total),
                'sort_date' => $documentDate,
                'sort_id' => $invoice->id,
            ]);
        }

        foreach ($documents->groupBy('supplier_id') as $supplierId => $supplierDocuments) {
            $remainingPayments = Decimal::normalize((string) Payment::query()
                ->where('party_type', 'supplier')
                ->where('party_id', $supplierId)
                ->whereDate('payment_date', '<=', $asOf)
                ->where(function ($query) use ($asOf): void {
                    $query->whereNull('reversed_at')
                        ->orWhereDate('reversed_at', '>', $asOf);
                })
                ->sum('base_amount'));

            $supplierDocuments = $supplierDocuments
                ->sortBy(fn (array $document): string => $document['sort_date'].'-'.str_pad((string) $document['sort_id'], 12, '0', STR_PAD_LEFT))
                ->values();

            foreach ($supplierDocuments as $document) {
                $amount = $document['amount'];

                if (Decimal::gt($remainingPayments, '0')) {
                    $applied = Decimal::gt($remainingPayments, $amount) ? $amount : $remainingPayments;
                    $amount = Decimal::sub($amount, $applied);
                    $remainingPayments = Decimal::sub($remainingPayments, $applied);
                }

                if (! Decimal::gt($amount, '0')) {
                    continue;
                }

                $dueDate = CarbonImmutable::parse($document['due_date']);
                $days = max(0, $dueDate->diffInDays($date, false));
                $bucket = $this->bucket($days);
                $totals[$bucket] = Decimal::add($totals[$bucket], $amount);
                $totals['total'] = Decimal::add($totals['total'], $amount);

                $rows[] = [
                    'party' => $document['party'],
                    'document' => $document['document'],
                    'document_date' => $document['document_date'],
                    'due_date' => $document['due_date'],
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
