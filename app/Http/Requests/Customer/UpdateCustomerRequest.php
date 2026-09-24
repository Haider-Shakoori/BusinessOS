<?php

namespace App\Http\Requests\Customer;

use App\Support\Decimal;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Whitelisted customer update (Batch 10).
 *
 * Mirrors StoreCustomerRequest. business_id is never validated, so a forged
 * business_id in an update request can never move a customer between
 * businesses — ownership stays with the current business, and the route-model
 * binding (scoped by BelongsToBusiness) has already resolved the customer.
 *
 * Batch 18: opening balance fields mirror the store request (exact Decimal
 * normalization, non-negative only).
 */
class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function prepareForValidation(): void
    {
        $openingBalance = $this->input('opening_balance');

        // Guard with is_numeric before Decimal::normalize so a malformed value
        // reaches the validation rules (a clean error message) instead of
        // throwing a raw Decimal InvalidArgumentException.
        if ($openingBalance !== null && $openingBalance !== '' && is_numeric($openingBalance)) {
            $this->merge(['opening_balance' => Decimal::normalize($openingBalance)]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email:filter', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'opening_balance' => ['nullable', 'numeric', 'min:0', 'regex:/^\d{1,12}(\.\d{1,4})?$/'],
            'opening_balance_date' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('customers.validation.name_required'),
            'name.max' => __('customers.validation.name_max'),
            'company_name.max' => __('customers.validation.company_name_max'),
            'email.email' => __('customers.validation.email_invalid'),
            'email.max' => __('customers.validation.email_max'),
            'phone.max' => __('customers.validation.phone_max'),
            'address.max' => __('customers.validation.address_max'),
            'notes.max' => __('customers.validation.notes_max'),
            'opening_balance.numeric' => __('customers.validation.opening_balance_invalid'),
            'opening_balance.min' => __('customers.validation.opening_balance_min'),
            'opening_balance.regex' => __('customers.validation.opening_balance_invalid'),
            'opening_balance_date.date' => __('customers.validation.opening_balance_date_invalid'),
        ];
    }
}
