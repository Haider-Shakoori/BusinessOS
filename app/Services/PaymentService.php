<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Supplier;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Payment recording and reversal orchestration (Batch 16).
 *
 * The service owns the payment write path and is the ONLY authority over
 * financial reconciliation: both operations run inside one DB transaction and
 * lock the affected rows FOR UPDATE so concurrent requests serialize on the
 * document, not on application-level flags.
 *
 * record(): locks the invoice row, re-checks payable state against the CURRENT
 * database row (fresh casts), computes the remaining balance strictly from the
 * ACTIVE (non-reversed) allocations, rejects any amount above that balance at
 * the service level (the form request cannot know a live balance), allocates
 * the business-scoped never-reused PAY number (nested savepoint rolls back with
 * the outer transaction on any later failure), writes the payment + its single
 * allocation, then reconciles the invoice caches.
 *
 * reverse(): locks the payment row, refuses a second reversal (only one
 * transition ever), locks the target invoice, marks the payment reversed, and
 * reconciles. Reversal is the ONLY destructor of financial effect — the row and
 * its number are never deleted, so a reversed payment remains a visible audit
 * record that is simply excluded from every active aggregate.
 *
 * The customer is always inferred through the invoice (party_type 'customer',
 * party_id = the invoice's customer_id as it was at recording time); a request
 * can never substitute it. Business ownership is enforced by the
 * BelongsToBusiness global scopes on Invoice/Payment; this service never
 * accepts a business_id, payment_number, or any cache value (amount_paid /
 * amount_due / status) from a request.
 */
class PaymentService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly CurrencyService $currencies,
        private readonly SupplierLedgerService $supplierLedger,
    ) {
        //
    }

    /**
     * Record a payment against an invoice and reconcile the invoice.
     *
     * @param  array{...}  $validated  sanitised StorePaymentRequest payload
     * @return Payment the persisted payment (invoice + creator populated)
     *
     * @throws ValidationException when the invoice is not payable here or the
     *                             amount exceeds the remaining balance
     */
    public function record(array $validated, int $createdBy): Payment
    {
        $number = null;

        $payment = DB::transaction(function () use ($validated, $createdBy, &$number) {
            $invoice = Invoice::query()
                ->whereKey($validated['invoice_id'])
                ->lockForUpdate()
                ->first();

            $this->assertPayable($invoice);

            $amount = Decimal::normalize((string) $validated['amount']);
            $due = $this->balanceDue($invoice);

            if (Decimal::gt($amount, $due)) {
                throw ValidationException::withMessages([
                    'amount' => __('payments.validation.amount_exceeds_balance'),
                ]);
            }

            $number = $this->numbers->next(DocumentType::Payment);

            // Batch 19: a payment ALWAYS settles in the invoice's currency. The
            // snapshot (currency_code, exchange_rate) is copied from the invoice,
            // and base_amount converts the paid amount at that same rate — a
            // forged or mismatched currency on the request is impossible (the
            // field is prohibited in StorePaymentRequest) and the invoice itself
            // never changes currency after it leaves draft.
            $code = $invoice->currency_code ?? $this->currencies->baseCurrency();
            $rate = $invoice->exchange_rate ?? '1';

            $payment = new Payment;
            $payment->forceFill([
                'payment_number' => $number,
                'paymentable_type' => $invoice->getMorphClass(),
                'paymentable_id' => $invoice->getKey(),
                'party_type' => 'customer',
                'party_id' => $invoice->customer_id,
                'payment_date' => $validated['payment_date'],
                'amount' => $amount,
                'currency_code' => $code,
                'exchange_rate' => $rate,
                'base_amount' => $this->currencies->toBase($amount, $rate),
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $createdBy,
            ])->save();

            $payment->allocations()->create([
                'invoice_id' => $invoice->getKey(),
                'amount' => $amount,
            ]);

            $this->reconcile($invoice);

            return $payment;
        });

        return $payment->fresh(['invoice', 'createdBy']);
    }

    /**
     * Record an outgoing payment to a supplier. Supplier payments reuse the
     * same auditable payment table but do not create an invoice allocation.
     *
     * @param  array{amount:string|int|float,payment_method:string,payment_date:string,reference?:string|null,notes?:string|null}  $validated
     */
    public function recordSupplier(Supplier $supplier, array $validated, int $createdBy): Payment
    {
        return DB::transaction(function () use ($supplier, $validated, $createdBy): Payment {
            $locked = Supplier::query()->lockForUpdate()->findOrFail($supplier->id);
            $amount = Decimal::normalize((string) $validated['amount']);
            $due = $this->supplierLedger->outstandingBalance($locked);

            if (Decimal::gt($amount, $due)) {
                throw ValidationException::withMessages([
                    'amount' => __('suppliers.validation.payment_exceeds_balance'),
                ]);
            }

            $currency = $this->currencies->baseCurrency();

            $payment = new Payment;
            $payment->forceFill([
                'payment_number' => $this->numbers->next(DocumentType::Payment),
                'paymentable_type' => Supplier::class,
                'paymentable_id' => $locked->id,
                'party_type' => 'supplier',
                'party_id' => $locked->id,
                'payment_date' => $validated['payment_date'],
                'amount' => $amount,
                'currency_code' => $currency,
                'exchange_rate' => '1.00000000',
                'base_amount' => $amount,
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $createdBy,
            ])->save();

            return $payment->fresh(['supplier', 'createdBy']);
        });
    }

    /**
     * Mark a payment as reversed (never deleted) and reconcile the invoice.
     *
     * @throws ValidationException when the payment is already reversed
     */
    public function reverse(Payment $payment, ?string $reason, int $reversedBy): void
    {
        DB::transaction(function () use ($payment, $reason, $reversedBy) {
            // Lock the row so a racing identical reversal serializes here and
            // re-reads the authored row BEFORE the second lock attempt: the
            // already-reversed check below then fails for the loser, leaving
            // exactly one transition.
            $locked = Payment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->reversed_at !== null) {
                throw ValidationException::withMessages([
                    'payment' => __('payments.validation.already_reversed'),
                ]);
            }

            if ($locked->party_type === 'supplier') {
                $locked->forceFill([
                    'reversed_at' => now(),
                    'reversed_by' => $reversedBy,
                    'reversal_reason' => $reason,
                ])->save();

                return;
            }

            $invoice = Invoice::query()
                ->whereKey($locked->paymentable_id)
                ->lockForUpdate()
                ->first();

            if ($invoice === null) {
                throw new RuntimeException('Payment target invoice no longer exists.');
            }

            $locked->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $reversedBy,
                'reversal_reason' => $reason,
            ])->save();

            $this->reconcile($invoice);
        });
    }

    /**
     * Synchronise the invoice's cached payment balances (amount_paid /
     * amount_due) against the active allocations — and ONLY the balances. The
     * payment-derived status is intentionally left untouched here so this can
     * initialise the caches on invoice create/convert and re-sync on draft
     * edits without ever promoting a draft. record() and reverse() call the
     * private reconcile(), which reuses this and then derives the status.
     */
    public function reconcileBalance(Invoice $invoice): void
    {
        $paid = $this->activePaid($invoice);
        $due = Decimal::min(Decimal::sub((string) $invoice->total, $paid), '0');

        $invoice->forceFill([
            'amount_paid' => $paid,
            'amount_due' => $due,
        ])->save();
    }

    /**
     * Reconcile the invoice's derived caches AND the payment-derived status.
     * The balances are recomputed by reconcileBalance() from the authoritative
     * allocations; the status is then derived from the fresh values. Called on
     * record() and reverse() only — anything the DB currently holds (including
     * a forged value) is overwritten.
     */
    private function reconcile(Invoice $invoice): void
    {
        $this->reconcileBalance($invoice);

        $status = Decimal::eq($invoice->amount_due, '0')
            ? InvoiceStatus::Paid
            : (Decimal::gt($invoice->amount_paid, '0') ? InvoiceStatus::PartiallyPaid : InvoiceStatus::Sent);

        $invoice->forceFill(['status' => $status->value])->save();
    }

    /**
     * Sum of every ACTIVE (non-reversed) allocation against the invoice — the
     * authoritative financial source for amount_paid. Exact decimal math
     * tolerates the widest values: a full settle must equal the total exactly.
     */
    private function activePaid(Invoice $invoice): string
    {
        $amounts = PaymentAllocation::query()
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.invoice_id', $invoice->getKey())
            ->whereNull('payments.reversed_at')
            ->pluck('payment_allocations.amount');

        $total = '0.0000';

        foreach ($amounts as $amount) {
            $total = Decimal::add($total, Decimal::normalize((string) $amount));
        }

        return $total;
    }

    /** Closing balance: total minus active payments, clamped at zero. */
    private function balanceDue(Invoice $invoice): string
    {
        return Decimal::min(Decimal::sub((string) $invoice->total, $this->activePaid($invoice)), '0');
    }

    /**
     * Payability gate: Draft invoices are never payable (there is no silent
     * draft -> sent promotion), and a Paid invoice has nothing left to pay.
     */
    private function assertPayable(Invoice $invoice): void
    {
        if ($invoice === null) {
            throw ValidationException::withMessages([
                'invoice_id' => __('payments.validation.invalid_invoice'),
            ]);
        }

        if ($invoice->status === InvoiceStatus::Draft) {
            throw ValidationException::withMessages([
                'invoice_id' => __('payments.validation.draft_not_payable'),
            ]);
        }

        if ($invoice->status === InvoiceStatus::Paid) {
            throw ValidationException::withMessages([
                'invoice_id' => __('payments.validation.invoice_paid'),
            ]);
        }
    }
}
