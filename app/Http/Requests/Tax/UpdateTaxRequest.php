<?php

namespace App\Http\Requests\Tax;

use App\Rules\UniqueInBusiness;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Whitelisted tax update (Batch 11).
 *
 * Mirrors StoreTaxRequest with the uniqueness check ignoring the row being
 * edited. business_id is never validated, so a forged business_id in an update
 * request can never move a tax between businesses — ownership stays with the
 * current business, and the route-model binding (scoped by BelongsToBusiness)
 * has already resolved the tax.
 */
class UpdateTaxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $tax = $this->route('tax');

        return [
            'name' => ['required', 'string', 'max:100', new UniqueInBusiness('taxes', 'name', $tax?->id)],
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
