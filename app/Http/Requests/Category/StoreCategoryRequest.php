<?php

namespace App\Http\Requests\Category;

use App\Rules\UniqueInBusiness;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Whitelisted category creation (Batch 11).
 *
 * Only the lookup fields below are accepted. A request-supplied business_id is
 * not part of the rules, so it never appears in validated() and can never
 * reach the model — ownership is assigned from BusinessContext by the
 * BelongsToBusiness trait.
 */
class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', new UniqueInBusiness('categories')],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('categories.validation.name_required'),
            'name.max' => __('categories.validation.name_max'),
            'description.max' => __('categories.validation.description_max'),
            'name.unique' => __('categories.validation.name_unique'),
        ];
    }
}
