<?php

namespace App\Http\Requests\Unit;

use App\Rules\UniqueInBusiness;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Whitelisted unit update (Batch 11).
 *
 * Mirrors StoreUnitRequest with the uniqueness check ignoring the row being
 * edited. business_id is never validated, so a forged business_id in an update
 * request can never move a unit between businesses — ownership stays with the
 * current business, and the route-model binding (scoped by BelongsToBusiness)
 * has already resolved the unit.
 */
class UpdateUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $unit = $this->route('unit');

        return [
            'name' => ['required', 'string', 'max:100', new UniqueInBusiness('units', 'name', $unit?->id)],
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
