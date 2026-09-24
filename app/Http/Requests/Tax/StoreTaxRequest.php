<?php

namespace App\Http\Requests\Tax;

use App\Rules\UniqueInBusiness;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Whitelisted tax creation (Batch 11).
 *
 * Only the lookup fields below are accepted: a display name and a DECIMAL
 * rate. The rate is validated to 0–100 with up to 4 decimal places — never
 * a FLOAT/DOUBLE, mirroring the approved DECIMAL(8,4) precision decision.
 *
 * A request-supplied business_id is not part of the rules, so it never
 * appears in validated() and can never reach the model — ownership is
 * assigned from BusinessContext by the BelongsToBusiness trait.
 */
class StoreTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', new UniqueInBusiness('taxes')],
            'rate' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('taxes.validation.name_required'),
            'name.max' => __('taxes.validation.name_max'),
            'name.unique' => __('taxes.validation.name_unique'),
            'rate.required' => __('taxes.validation.rate_required'),
            'rate.numeric' => __('taxes.validation.rate_numeric'),
            'rate.min' => __('taxes.validation.rate_min'),
            'rate.max' => __('taxes.validation.rate_max'),
            'rate.decimal' => __('taxes.validation.rate_decimal'),
        ];
    }
}
