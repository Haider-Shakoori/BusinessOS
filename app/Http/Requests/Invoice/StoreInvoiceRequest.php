<?php

namespace App\Http\Requests\Invoice;

use App\Services\BusinessContext;
use App\Services\BusinessSettings;
use App\Services\CurrencyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Whitelisted invoice creation (Batch 15).
 *
 * Only approved Batch 15 fields are accepted. A request-supplied business_id,
 * invoice_number, quotation_id, or money total (subtotal/tax_amount/total) is
 * not part of the rules, so it never appears in validated() and can never reach
 * the model — ownership, the number and the amounts are assigned server-side
 * (BelongsToBusiness trait + InvoiceService/QuotationCalculator). The source
 * quotation reference is set exclusively by the conversion path.
 *
 * Settable statuses are the ONLY human-authorable invoice states: `draft` and
 * `sent`. Payment-derived states (partially_paid / paid) are set exclusively by
 * PaymentService reconciliation and are intentionally NOT accepted here. A
 * request can never supply a quotation_id or business_id.
 *
 * tenant-safe references: customer_id and each item's product_id/tax_id are
 * validated with a scoped exists rule that requires the target row to belong
 * to the CURRENT business AND not be soft-deleted. tax_id is validated ONLY
 * while the optional-tax feature is enabled — when it is off the key is
 * dropped from the rules entirely, so a forged tax_id can never assign a tax.
 *
 * Items: each line must have a description, a positive quantity and a
 * non-negative unit price (both 4-dp decimals), and an optional product/tax
 * reference. The item minimal count (1) is enforced so an empty invoice can
 * never be written with a header total of zero.
 */
class StoreInvoiceRequest extends FormRequest
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
            'status' => ['required', Rule::in(self::settableStatuses())],
            'discount_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999.9999', 'decimal:0,4'],
            'notes' => ['nullable', 'string', 'max:2000'],
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
            'customer_id.required' => __('invoices.validation.customer_id_required'),
            'customer_id.exists' => __('invoices.validation.customer_id_exists'),
            'date.required' => __('invoices.validation.date_required'),
            'date.date' => __('invoices.validation.date_date'),
            'status.required' => __('invoices.validation.status_required'),
            'status.in' => __('invoices.validation.status_in'),
            'discount_type.in' => __('invoices.validation.discount_type_in'),
            'discount_amount.numeric' => __('invoices.validation.discount_amount_numeric'),
            'discount_amount.min' => __('invoices.validation.discount_amount_min'),
            'discount_amount.max' => __('invoices.validation.discount_amount_max'),
            'discount_amount.decimal' => __('invoices.validation.discount_amount_decimal'),
            'notes.max' => __('invoices.validation.notes_max'),
            'currency_code.size' => __('currencies.validation.invalid'),
            'currency_code.alpha' => __('currencies.validation.invalid'),
            'currency_code.in' => __('currencies.validation.invalid'),
            'items.required' => __('invoices.validation.items_required'),
            'items.min' => __('invoices.validation.items_min'),
            'items.*.product_id.exists' => __('invoices.validation.item_product_exists'),
            'items.*.description.required' => __('invoices.validation.item_description_required'),
            'items.*.description.max' => __('invoices.validation.item_description_max'),
            'items.*.quantity.required' => __('invoices.validation.item_quantity_required'),
            'items.*.quantity.numeric' => __('invoices.validation.item_quantity_numeric'),
            'items.*.quantity.gt' => __('invoices.validation.item_quantity_min'),
            'items.*.quantity.max' => __('invoices.validation.item_quantity_max'),
            'items.*.quantity.decimal' => __('invoices.validation.item_quantity_decimal'),
            'items.*.unit_price.required' => __('invoices.validation.item_unit_price_required'),
            'items.*.unit_price.numeric' => __('invoices.validation.item_unit_price_numeric'),
            'items.*.unit_price.min' => __('invoices.validation.item_unit_price_min'),
            'items.*.unit_price.max' => __('invoices.validation.item_unit_price_max'),
            'items.*.unit_price.decimal' => __('invoices.validation.item_unit_price_decimal'),
            'items.*.tax_id.exists' => __('invoices.validation.item_tax_exists'),
        ];
    }

    /**
     * The ONLY statuses a form may ever set: `draft` and `sent` (the two
     * human-authorable states). This intentional literal (NOT derived from
     * InvoiceStatus::cases()) keeps the payment-derived statuses —
     * partially_paid and paid — forever out of reach of any request: they are
     * written exclusively by PaymentService during reconciliation.
     *
     * @return list<string>
     */
    public static function settableStatuses(): array
    {
        return ['draft', 'sent'];
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
