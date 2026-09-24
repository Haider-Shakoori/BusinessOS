<?php

namespace App\Http\Requests\Expense;

use App\Enums\PaymentMethod;
use App\Services\BusinessContext;
use App\Services\CurrencyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Whitelisted expense creation (Batch 17).
 *
 * Only the approved expense fields are accepted. A request-supplied
 * business_id, expense_number, receipt_path, created_by or totals are NOT part
 * of the rules, so they can never appear in validated() and can never reach
 * the service — ownership, the never-reused EXP number, the storage path, and
 * the author are assigned server-side (BelongsToBusiness + ExpenseService).
 *
 * category_id is validated with a tenant-scoped exists rule: the row must
 * belong to the CURRENT business AND not be soft-deleted.
 *
 * amount must be > 0, DECIMAL(16,4)-compatible, and never a float.
 *
 * The receipt is validated against a strict safe-format whitelist
 * (jpg/jpeg/png/webp/pdf) and a 5 MB cap; executable formats are never
 * accepted. The stored filename is always generated server-side.
 */
class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'integer', $this->scopedExists('categories')],
            'expense_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.9999', 'decimal:0,4'],
            'payment_method' => ['nullable', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'reference' => ['nullable', 'string', 'max:100'],
            'vendor' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha', Rule::in(app(CurrencyService::class)->enabledCodes())],
            'receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => __('expenses.validation.category_id_exists'),
            'expense_date.required' => __('expenses.validation.expense_date_required'),
            'expense_date.date' => __('expenses.validation.expense_date_date'),
            'amount.required' => __('expenses.validation.amount_required'),
            'amount.numeric' => __('expenses.validation.amount_numeric'),
            'amount.gt' => __('expenses.validation.amount_min'),
            'amount.max' => __('expenses.validation.amount_max'),
            'amount.decimal' => __('expenses.validation.amount_decimal'),
            'payment_method.in' => __('expenses.validation.payment_method_in'),
            'reference.max' => __('expenses.validation.reference_max'),
            'vendor.max' => __('expenses.validation.vendor_max'),
            'notes.max' => __('expenses.validation.notes_max'),
            'receipt.file' => __('expenses.validation.receipt_file'),
            'receipt.mimes' => __('expenses.validation.receipt_mimes'),
            'receipt.max' => __('expenses.validation.receipt_max'),
            'currency_code.in' => __('currencies.validation.invalid'),
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
}
