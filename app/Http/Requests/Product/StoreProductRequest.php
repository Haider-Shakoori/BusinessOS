<?php

namespace App\Http\Requests\Product;

use App\Enums\ProductType;
use App\Rules\UniqueInBusiness;
use App\Services\BusinessContext;
use App\Services\BusinessSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Whitelisted product/service creation (Batch 12).
 *
 * Only the approved Batch 12 fields are accepted. A request-supplied
 * business_id is not part of the rules, so it never appears in validated()
 * and can never reach the model — ownership is assigned from BusinessContext
 * by the BelongsToBusiness trait.
 *
 * tenant-safe references: category_id / unit_id / tax_id are validated with a
 * scoped exists rule that requires the target row to belong to the CURRENT
 * business AND not be soft-deleted. A plain `exists:A,id` is intentionally NOT
 * used: it would accept a row from another business.
 *
 * tax_id is validated ONLY while the optional-tax feature is enabled. When
 * tax is disabled the key is dropped from the rules entirely, so a forged
 * tax_id can never assign a tax on creation.
 */
class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $rules = [
            'type' => ['required', Rule::enum(ProductType::class)],
            'name' => ['required', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'max:50', new UniqueInBusiness('products', 'sku')],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['nullable', 'integer', $this->scopedExists('categories')],
            'unit_id' => ['nullable', 'integer', $this->scopedExists('units')],
            'sale_price' => ['required', 'numeric', 'min:0', 'max:999999999999.9999', 'decimal:0,4'],
        ];

        if ($this->taxesEnabled()) {
            $rules['tax_id'] = ['nullable', 'integer', $this->scopedExists('taxes')];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'type.required' => __('products.validation.type_required'),
            'type.enum' => __('products.validation.type_enum'),
            'name.required' => __('products.validation.name_required'),
            'name.max' => __('products.validation.name_max'),
            'sku.max' => __('products.validation.sku_max'),
            'sku.unique' => __('products.validation.sku_unique'),
            'description.max' => __('products.validation.description_max'),
            'category_id.exists' => __('products.validation.category_id_exists'),
            'unit_id.exists' => __('products.validation.unit_id_exists'),
            'tax_id.exists' => __('products.validation.tax_id_exists'),
            'sale_price.required' => __('products.validation.sale_price_required'),
            'sale_price.numeric' => __('products.validation.sale_price_numeric'),
            'sale_price.min' => __('products.validation.sale_price_min'),
            'sale_price.max' => __('products.validation.sale_price_max'),
            'sale_price.decimal' => __('products.validation.sale_price_decimal'),
        ];
    }

    /**
     * A tenant-scoped exists rule: the referenced row must belong to the
     * current business and must not be soft-deleted.
     */
    private function scopedExists(string $table): Exists
    {
        $businessId = app(BusinessContext::class)->currentId();

        return Rule::exists($table, 'id')
            ->where(fn ($query) => $query->where('business_id', $businessId)->whereNull('deleted_at'));
    }

    /**
     * Whether the optional-tax feature is enabled for the current business.
     */
    private function taxesEnabled(): bool
    {
        return (bool) app(BusinessSettings::class)->get('general.tax_enabled', false);
    }
}
