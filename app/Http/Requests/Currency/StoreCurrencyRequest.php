<?php

namespace App\Http\Requests\Currency;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Enable a currency for the current business (Batch 19).
 *
 * Only an ACTIVE code from the shared registry may be enabled — an inactive
 * code (decommissioned in the catalogue) can never be re-enabled here, though
 * historical documents that snapshot it keep rendering. The base currency and
 * already-enabled currencies are rejected inside the controller, not here,
 * because those rules depend on the current business's runtime state rather
 * than the catalogue alone.
 */
class StoreCurrencyRequest extends FormRequest
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
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'currency_code.required' => __('currencies.validation.required'),
            'currency_code.size' => __('currencies.validation.invalid'),
            'currency_code.alpha' => __('currencies.validation.invalid'),
            'currency_code.exists' => __('currencies.validation.invalid'),
        ];
    }
}
