<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceAdjustment;
use App\Models\SupplierPaymentAllocation;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierInvoiceSettlementService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AccountingPostingService $accounting,
    ) {
        //
    }

    public function reconcile(SupplierInvoice $supplierInvoice): SupplierInvoice
    {
        $invoice = SupplierInvoice::query()
            ->lockForUpdate()
            ->findOrFail($supplierInvoice->id);

        if ($invoice->status !== 'approved') {
            $invoice->forceFill([
                'amount_paid' => '0.0000',
                'credit_total' => '0.0000',
                'debit_total' => '0.0000',
                'amount_due' => '0.0000',
                'settlement_status' => 'unpaid',
            ])->save();

            return $invoice->refresh();
        }

        $paid = Decimal::normalize((string) SupplierPaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'supplier_payment_allocations.payment_id')
            ->where('supplier_payment_allocations.supplier_invoice_id', $invoice->id)
            ->whereNull('payments.reversed_at')
            ->sum('supplier_payment_allocations.amount'));

        $credit = Decimal::normalize((string) SupplierInvoiceAdjustment::query()
            ->where('supplier_invoice_id', $invoice->id)
            ->where('type', 'credit')
            ->whereNull('reversed_at')
            ->sum('amount'));

        $debit = Decimal::normalize((string) SupplierInvoiceAdjustment::query()
            ->where('supplier_invoice_id', $invoice->id)
            ->where('type', 'debit')
            ->whereNull('reversed_at')
            ->sum('amount'));

        $obligation = Decimal::add((string) $invoice->total, $debit);
        $settled = Decimal::add($paid, $credit);
        $rawDue = Decimal::sub($obligation, $settled);
        $due = Decimal::lt($rawDue, '0') ? '0.0000' : $rawDue;

        $status = match (true) {
            Decimal::isZero($due) => 'paid',
            Decimal::gt($settled, '0') => 'partially_paid',
            default => 'unpaid',
        };

        $invoice->forceFill([
            'amount_paid' => $paid,
            'credit_total' => $credit,
            'debit_total' => $debit,
            'amount_due' => $due,
            'settlement_status' => $status,
        ])->save();

        return $invoice->refresh();
    }

    public function createAdjustment(
        SupplierInvoice $supplierInvoice,
        string $type,
        string $amount,
        string $date,
        ?string $reason,
        int $userId,
    ): SupplierInvoiceAdjustment {
        return DB::transaction(function () use ($supplierInvoice, $type, $amount, $date, $reason, $userId): SupplierInvoiceAdjustment {
            $invoice = SupplierInvoice::query()
                ->with('purchaseOrder')
                ->lockForUpdate()
                ->findOrFail($supplierInvoice->id);

            if ($invoice->status !== 'approved' || $invoice->purchaseOrder?->ap_recognition !== 'invoice') {
                throw ValidationException::withMessages([
                    'supplier_invoice' => 'Only approved invoice-recognition supplier invoices can be adjusted.',
                ]);
            }

            if (! in_array($type, ['credit', 'debit'], true)) {
                throw ValidationException::withMessages([
                    'type' => 'Supplier invoice adjustment type must be credit or debit.',
                ]);
            }

            $amount = Decimal::normalize($amount);

            if (! Decimal::gt($amount, '0')) {
                throw ValidationException::withMessages([
                    'amount' => 'Adjustment amount must be greater than zero.',
                ]);
            }

            $invoice = $this->reconcile($invoice);

            if ($type === 'credit' && Decimal::gt($amount, (string) $invoice->amount_due)) {
                throw ValidationException::withMessages([
                    'amount' => 'Supplier credit note cannot exceed the invoice outstanding balance.',
                ]);
            }

            $adjustment = SupplierInvoiceAdjustment::create([
                'supplier_invoice_id' => $invoice->id,
                'supplier_id' => $invoice->supplier_id,
                'number' => $this->numbers->next(
                    $type === 'credit'
                        ? DocumentType::SupplierCreditNote
                        : DocumentType::SupplierDebitNote,
                ),
                'type' => $type,
                'note_date' => $date,
                'amount' => $amount,
                'reason' => $reason,
                'created_by' => $userId,
            ]);

            $this->accounting->postSupplierInvoiceAdjustment($adjustment);
            $this->reconcile($invoice);

            return $adjustment->load(['supplierInvoice', 'supplier', 'creator']);
        });
    }

    public function reverseAdjustment(
        SupplierInvoiceAdjustment $adjustment,
        int $userId,
        ?string $reason = null,
    ): SupplierInvoiceAdjustment {
        return DB::transaction(function () use ($adjustment, $userId, $reason): SupplierInvoiceAdjustment {
            $locked = SupplierInvoiceAdjustment::query()
                ->lockForUpdate()
                ->findOrFail($adjustment->id);

            if ($locked->reversed_at !== null) {
                throw ValidationException::withMessages([
                    'adjustment' => 'This supplier invoice adjustment has already been reversed.',
                ]);
            }

            $locked->forceFill([
                'reversed_by' => $userId,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ])->save();

            $this->accounting->reverseSupplierInvoiceAdjustment(
                $locked,
                $reason ?: 'Supplier invoice adjustment reversed',
            );

            $this->reconcile($locked->supplierInvoice);

            return $locked->refresh();
        });
    }

    public function allocate(
        Payment $payment,
        SupplierInvoice $supplierInvoice,
        string $amount,
    ): SupplierPaymentAllocation {
        $invoice = SupplierInvoice::query()
            ->with('purchaseOrder')
            ->lockForUpdate()
            ->findOrFail($supplierInvoice->id);

        if ($payment->party_type !== 'supplier'
            || (int) $payment->party_id !== (int) $invoice->supplier_id
            || $payment->reversed_at !== null) {
            throw ValidationException::withMessages([
                'supplier_invoice' => 'The supplier payment does not belong to this supplier invoice.',
            ]);
        }

        if ($invoice->status !== 'approved' || $invoice->purchaseOrder?->ap_recognition !== 'invoice') {
            throw ValidationException::withMessages([
                'supplier_invoice' => 'Only approved invoice-recognition supplier invoices can receive payment allocations.',
            ]);
        }

        $invoice = $this->reconcile($invoice);
        $amount = Decimal::normalize($amount);

        if (! Decimal::gt($amount, '0') || Decimal::gt($amount, (string) $invoice->amount_due)) {
            throw ValidationException::withMessages([
                'amount' => 'Supplier payment allocation exceeds the invoice outstanding balance.',
            ]);
        }

        $allocatedOnPayment = Decimal::normalize((string) SupplierPaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->sum('amount'));

        if (Decimal::gt(Decimal::add($allocatedOnPayment, $amount), (string) $payment->base_amount)) {
            throw ValidationException::withMessages([
                'amount' => 'Supplier payment allocations cannot exceed the payment amount.',
            ]);
        }

        $allocation = SupplierPaymentAllocation::create([
            'payment_id' => $payment->id,
            'supplier_invoice_id' => $invoice->id,
            'amount' => $amount,
        ]);

        $this->reconcile($invoice);

        return $allocation;
    }

    public function autoAllocate(Payment $payment, Supplier $supplier, string $amount): string
    {
        $remaining = Decimal::normalize($amount);
        $allocated = '0.0000';

        $invoices = SupplierInvoice::query()
            ->where('supplier_id', $supplier->id)
            ->where('status', 'approved')
            ->where('settlement_status', '!=', 'paid')
            ->whereHas('purchaseOrder', fn ($query) => $query->where('ap_recognition', 'invoice'))
            ->orderByRaw('COALESCE(due_date, invoice_date) asc')
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($invoices as $invoice) {
            if (! Decimal::gt($remaining, '0')) {
                break;
            }

            $invoice = $this->reconcile($invoice);

            if (! Decimal::gt((string) $invoice->amount_due, '0')) {
                continue;
            }

            $amountToAllocate = Decimal::gt($remaining, (string) $invoice->amount_due)
                ? (string) $invoice->amount_due
                : $remaining;

            $this->allocate($payment, $invoice, $amountToAllocate);
            $allocated = Decimal::add($allocated, $amountToAllocate);
            $remaining = Decimal::sub($remaining, $amountToAllocate);
        }

        return $allocated;
    }

    public function reconcilePaymentInvoices(Payment $payment): void
    {
        $invoiceIds = SupplierPaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->pluck('supplier_invoice_id');

        foreach ($invoiceIds as $invoiceId) {
            $invoice = SupplierInvoice::query()->find($invoiceId);

            if ($invoice !== null) {
                $this->reconcile($invoice);
            }
        }
    }
}
