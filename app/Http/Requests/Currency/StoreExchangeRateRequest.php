<?php

namespace App\Http\Requests\Currency;

use App\Services\BusinessContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Add or correct an exchange rate for the current business (Batch 19).
 *
 * Two guard rails keep the rates table honest:
 *
 *  - The code must be a genuinely ENABLED currency of the business (a
 *    business_currencies row), so rates can only ever be priced for currencies
 *    the business actually uses. The base currency earns its rate implicitly
 *    (always 1) and is not in business_currencies, so it can never get a row
 *    here.
 *
 *  - The rate must be a positive DECIMAL(16,8)-compatible value — a zero or
 *    negative rate would make base_amount zero or negative and is rejected
 *    outright. Max width matches the DECIMAL(16,8) column (8 integers + 8
 *    decimals).
 *
 * effective_date is free: documenting a date in the future (a scheduled
 * branch) or past (back-fixing a historical branch) is legal — resolution is
 * always "newest branch at-or-before the document date".
 */
class StoreExchangeRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'currency_code' => [
                'required', 'string', 'size:3', 'alpha',
                Rule::exists('currencies', 'code')->where('is_active', true),
                $this->scopedEnabled(),
            ],
            'rate' => ['required', 'numeric', 'gt:0', 'max:99999999.99999999', 'decimal:0,8'],
            'effective_date' => ['required', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'currency_code.required' => __('currencies.validation.required'),
            'currency_code.size' => __('currencies.validation.invalid'),
            'currency_code.alpha' => __('currencies.validation.invalid'),
            'currency_code.exists' => __('currencies.validation.not_enabled'),
            'currency_code.business_currencies' => __('currencies.validation.not_enabled'),
            'rate.required' => __('currencies.validation.rate_required'),
            'rate.numeric' => __('currencies.validation.rate_invalid'),
            'rate.gt' => __('currencies.validation.rate_positive'),
            'rate.max' => __('currencies.validation.rate_invalid'),
            'rate.decimal' => __('currencies.validation.rate_invalid'),
            'effective_date.required' => __('currencies.validation.effective_date_required'),
            'effective_date.date' => __('currencies.validation.effective_date_invalid'),
        ];
    }

    /**
     * The code must be enabled for the current business (a real
     * business_currencies row) — never just a globally known ISO code.
     */
    private function scopedEnabled(): Exists
    {
        return Rule::exists('business_currencies', 'currency_code')
            ->where('business_id', app(BusinessContext::class)->currentId());
    }
}
