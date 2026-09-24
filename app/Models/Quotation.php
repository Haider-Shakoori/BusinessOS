<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Business-owned quotation header (Batch 14).
 *
 * Tenancy follows the standard BelongsToBusiness convention: business_id is
 * auto-assigned from the current BusinessContext on creation, is never in
 * $fillable (request-supplied business_id is ignored), and every query is
 * filtered to the current business by the named `business` global scope.
 *
 * quotation_number is allocated exclusively by the DocumentNumberService
 * inside the same transaction as the create, and there is a composite unique
 * constraint on (business_id, quotation_number). It is deliberately NOT in
 * $fillable: request-supplied numbers and totals are ignored; every amount is
 * computed server-side by the QuotationCalculator from the validated lines.
 *
 * The discount is document-level (percentage or fixed) and stored as raw
 * discount_type + discount_amount; the resolved discount (clamped to the
 * subtotal) and the final total are derivative and recomputed by the
 * calculator, never accepted from a request.
 *
 * customer_id is nullable (nullOnDelete) so quotation history survives the
 * soft/hard delete of the customer; the relation is resolved with
 * withTrashed() for historical display. Items stay point-in-time snapshots
 * (description, unit_price, tax_rate) and are never backfilled from the
 * current product/customer state.
 *
 * Lifecycle (see DECISIONS): draft quotations are editable/deletable; any
 * later status (sent/accepted/rejected/expired/converted) is immutable in
 * Batch 14. `converted` is reserved for the Batch 15 invoice conversion path
 * and is cast here only so stored rows read back as the correct enum.
 *
 * Deletion is a soft delete; numbers are never reused.
 */
class Quotation extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'date',
        'expiry_date',
        'status',
        'discount_type',
        'discount_amount',
        'notes',
        'terms',
        'currency_code',
        'exchange_rate',
        'base_amount',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'expiry_date' => 'date',
            'status' => QuotationStatus::class,
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'base_amount' => 'decimal:4',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The historical customer. Soft-deleted customers are kept so quotation
     * history renders; current pickers exclude deleted rows at validation.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuotationItem::class)->orderBy('sort_order');
    }

    public function createdBy(): BelongsTo
    {
        // No withTrashed() here: the users table has no soft-delete column, and
        // adding the call would throw (the SoftDeletes builder macro is only
        // registered when the RELATED model uses the trait). Users are never
        // removed, so the creator is always resolvable.
        return $this->belongsTo(User::class, 'created_by');
    }
}
