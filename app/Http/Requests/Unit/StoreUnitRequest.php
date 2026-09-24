<?php

namespace App\Http\Requests\Unit;

use App\Rules\UniqueInBusiness;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Whitelisted unit creation (Batch 11).
 *
 * Only the lookup fields below are accepted. A request-supplied business_id is
 * not part of the rules, so it never appears in validated() and can never
 * reach the model — ownership is assigned from BusinessContext by the
 * BelongsToBusiness trait.
 */
class StoreUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', new UniqueInBusiness('units')],
            'short_name' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('units.validation.name_required'),
            'name.max' => __('units.validation.name_max'),
            'name.unique' => __('units.validation.name_unique'),
            'short_name.max' => __('units.validation.short_name_max'),
        ];
    }
}
