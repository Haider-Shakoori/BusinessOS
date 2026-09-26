<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupplierPaymentService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly CurrencyService $currencies,
    ) {
        //
    }

    /**
     * @param  array{supplier_id: int, purchase_order_id?: int|null, payment_date: string, amount: string|int|float, payment_method: string, reference?: string|null, notes?: string|null}  $validated
     */
    public function record(array $validated, int $createdBy): Payment
    {
        return DB::transaction(function () use ($validated, $createdBy): Payment {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($validated['supplier_id']);
            $purchase = null;

            if (! empty($validated['purchase_order_id'])) {
                $purchase = PurchaseOrder::query()
                    ->where('supplier_id', $supplier->id)
                    ->whereKey($validated['purchase_order_id'])
                    ->lockForUpdate()
                    ->first();

                if ($purchase === null || $purchase->status !== 'received') {
                    throw ValidationException::withMessages([
                        'purchase_order_id' => __('suppliers.validation.invalid_purchase'),
                    ]);
                }
            }

            $amount = Decimal::normalize((string) $validated['amount']);

            if (! Decimal::gt($amount, '0')) {
                throw ValidationException::withMessages([
                    'amount' => __('suppliers.validation.payment_positive'),
                ]);
            }

            $base = $this->currencies->baseCurrency();

            $payment = new Payment;
            $payment->forceFill([
                'payment_number' => $this->numbers->next(DocumentType::Payment),
                'paymentable_type' => $purchase?->getMorphClass() ?? $supplier->getMorphClass(),
                'paymentable_id' => $purchase?->getKey() ?? $supplier->getKey(),
                'party_type' => 'supplier',
                'party_id' => $supplier->id,
                'payment_date' => $validated['payment_date'],
                'amount' => $amount,
                'currency_code' => $base,
                'exchange_rate' => '1',
                'base_amount' => $amount,
                'payment_method' => $validated['payment_method'],
                'reference' => $validated['reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'created_by' => $createdBy,
            ])->save();

            return $payment->fresh('createdBy');
        });
    }

    public function reverse(Payment $payment, ?string $reason, int $reversedBy): void
    {
        DB::transaction(function () use ($payment, $reason, $reversedBy): void {
            $locked = Payment::query()
                ->where('party_type', 'supplier')
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->reversed_at !== null) {
                throw ValidationException::withMessages([
                    'payment' => __('suppliers.validation.payment_already_reversed'),
                ]);
            }

            $locked->forceFill([
                'reversed_at' => now(),
                'reversed_by' => $reversedBy,
                'reversal_reason' => $reason,
            ])->save();
        });
    }
}
