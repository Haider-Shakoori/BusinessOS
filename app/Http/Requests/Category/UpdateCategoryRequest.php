<?php

namespace App\Http\Requests\Category;

use App\Rules\UniqueInBusiness;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Whitelisted category update (Batch 11).
 *
 * Mirrors StoreCategoryRequest with the uniqueness check ignoring the row being
 * edited. business_id is never validated, so a forged business_id in an update
 * request can never move a category between businesses — ownership stays with
 * the current business, and the route-model binding (scoped by
 * BelongsToBusiness) has already resolved the category.
 */
class UpdateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => ['required', 'string', 'max:100', new UniqueInBusiness('categories', 'name', $category?->id)],
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
