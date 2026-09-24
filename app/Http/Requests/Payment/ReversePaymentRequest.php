<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Whitelisted payment reversal (Batch 16).
 *
 * Accepts only the optional free-text reversal reason. Everything else about a
 * reversal is server-authored: reversed_at / reversed_by are set by
 * PaymentService, and the reason is truncated to a sane audit-comment length.
 */
class ReversePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'reversal_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reversal_reason.max' => __('payments.validation.reversal_reason_max'),
        ];
    }
}
