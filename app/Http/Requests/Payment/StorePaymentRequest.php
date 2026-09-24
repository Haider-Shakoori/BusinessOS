<?php

namespace App\Http\Requests\Payment;

use App\Enums\PaymentMethod;
use App\Services\BusinessContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Whitelisted payment recording (Batch 16).
 *
 * Only approved fields are accepted: the target invoice, the amount, the
 * payment channel, the business payment date, and optional reference/notes.
 * A request-supplied business_id, payment_number, paymentable/party fields, or
 * any cache value (amount_paid / amount_due / status / totals) is NOT part of
 * the rules, so it can never appear in validated() and can never reach the
 * service — ownership, the never-reused number, the polymorphic/party
 * reference and every reconciliation field are assigned server-side
 * (BelongsToBusiness trait + PaymentService).
 *
 * The customer is always inferred THROUGH the invoice; there is deliberately
 * no customer_id field for a payment.
 */
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', $this->scopedExists('invoices')],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.9999', 'decimal:0,4'],
            'payment_method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'payment_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'currency_code' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'invoice_id.required' => __('payments.validation.invoice_id_required'),
            'invoice_id.exists' => __('payments.validation.invoice_id_exists'),
            'amount.required' => __('payments.validation.amount_required'),
            'amount.numeric' => __('payments.validation.amount_numeric'),
            'amount.gt' => __('payments.validation.amount_min'),
            'amount.max' => __('payments.validation.amount_max'),
            'amount.decimal' => __('payments.validation.amount_decimal'),
            'payment_method.required' => __('payments.validation.payment_method_required'),
            'payment_method.in' => __('payments.validation.payment_method_in'),
            'payment_date.required' => __('payments.validation.payment_date_required'),
            'payment_date.date' => __('payments.validation.payment_date_date'),
            'reference.max' => __('payments.validation.reference_max'),
            'notes.max' => __('payments.validation.notes_max'),
            'currency_code.prohibited' => __('currencies.validation.prohibited'),
        ];
    }

    /**
     * A tenant-scoped exists rule: the target invoice must belong to the
     * current business and must not be soft-deleted.
     */
    private function scopedExists(string $table): Exists
    {
        $businessId = app(BusinessContext::class)->currentId();

        return Rule::exists($table, 'id')
            ->where(fn ($query) => $query->where('business_id', $businessId)->whereNull('deleted_at'));
    }
}
