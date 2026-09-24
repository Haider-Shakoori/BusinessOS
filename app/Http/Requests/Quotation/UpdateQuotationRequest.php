<?php

namespace App\Http\Requests\Quotation;

use App\Services\BusinessContext;
use App\Services\BusinessSettings;
use App\Services\CurrencyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Whitelisted quotation update (Batch 14).
 *
 * Identical field set to StoreQuotationRequest (customer, dates, status,
 * discount, notes, terms, items) — a quotation's editable surface is the same
 * whether it is being created or revised. The quotation number, business_id
 * and money totals are still excluded and never reach the model; the number is
 * immutable for the document's life.
 *
 * The draft-only lifecycle guard is enforced in QuotationService, which is the
 * authority on whether a quotation may still change; this request only
 * sanctions the FIELD SET. Same conversions, tenant-safe references and
 * optional-tax behaviour as the store request.
 */
class UpdateQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        $rules = [
            'customer_id' => ['required', 'integer', $this->scopedExists('customers')],
            'date' => ['required', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:date'],
            'status' => ['required', Rule::in(StoreQuotationRequest::settableStatuses())],
            'discount_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999', 'decimal:0,4'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha', Rule::in(app(CurrencyService::class)->enabledCodes())],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', $this->scopedExists('products')],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999999.9999', 'decimal:0,4'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:999999999999.9999', 'decimal:0,4'],
        ];

        if ($this->taxesEnabled()) {
            $rules['items.*.tax_id'] = ['nullable', 'integer', $this->scopedExists('taxes')];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'customer_id.required' => __('quotations.validation.customer_id_required'),
            'customer_id.exists' => __('quotations.validation.customer_id_exists'),
            'date.required' => __('quotations.validation.date_required'),
            'date.date' => __('quotations.validation.date_date'),
            'expiry_date.after_or_equal' => __('quotations.validation.expiry_date_after'),
            'status.required' => __('quotations.validation.status_required'),
            'status.in' => __('quotations.validation.status_in'),
            'discount_type.in' => __('quotations.validation.discount_type_in'),
            'discount_amount.numeric' => __('quotations.validation.discount_amount_numeric'),
            'discount_amount.min' => __('quotations.validation.discount_amount_min'),
            'discount_amount.max' => __('quotations.validation.discount_amount_max'),
            'discount_amount.decimal' => __('quotations.validation.discount_amount_decimal'),
            'notes.max' => __('quotations.validation.notes_max'),
            'terms.max' => __('quotations.validation.terms_max'),
            'currency_code.in' => __('currencies.validation.invalid'),
            'items.required' => __('quotations.validation.items_required'),
            'items.min' => __('quotations.validation.items_min'),
            'items.*.product_id.exists' => __('quotations.validation.item_product_exists'),
            'items.*.description.required' => __('quotations.validation.item_description_required'),
            'items.*.description.max' => __('quotations.validation.item_description_max'),
            'items.*.quantity.required' => __('quotations.validation.item_quantity_required'),
            'items.*.quantity.numeric' => __('quotations.validation.item_quantity_numeric'),
            'items.*.quantity.gt' => __('quotations.validation.item_quantity_min'),
            'items.*.quantity.max' => __('quotations.validation.item_quantity_max'),
            'items.*.quantity.decimal' => __('quotations.validation.item_quantity_decimal'),
            'items.*.unit_price.required' => __('quotations.validation.item_unit_price_required'),
            'items.*.unit_price.numeric' => __('quotations.validation.item_unit_price_numeric'),
            'items.*.unit_price.min' => __('quotations.validation.item_unit_price_min'),
            'items.*.unit_price.max' => __('quotations.validation.item_unit_price_max'),
            'items.*.unit_price.decimal' => __('quotations.validation.item_unit_price_decimal'),
            'items.*.tax_id.exists' => __('quotations.validation.item_tax_exists'),
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
