<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Business-owned invoice header (Batch 15).
 *
 * Tenancy follows the standard BelongsToBusiness convention: business_id is
 * auto-assigned from the current BusinessContext on creation, is never in
 * $fillable (request-supplied business_id is ignored), and every query is
 * filtered to the current business by the named `business` global scope.
 *
 * invoice_number is allocated exclusively by the DocumentNumberService
 * (DocumentType::Invoice) inside the same transaction as the create, and the
 * database enforces the composite unique constraint on
 * (business_id, invoice_number). It is deliberately NOT in $fillable:
 * request-supplied numbers and totals are ignored; every amount is computed
 * server-side by the QuotationCalculator from the validated lines. Numbers are
 * immutable after creation and never reused.
 *
 * The discount is document-level (percentage or fixed) and stored as raw
 * discount_type + discount_amount, mirroring quotations exactly so a converted
 * invoice keeps the source quotation's totals (no-drift). The resolved discount
 * and final total are derivative and recomputed by the calculator, never
 * accepted from a request.
 *
 * customer_id is nullable (nullOnDelete) so invoice history survives the
 * soft/hard delete of the customer; the relation is resolved with
 * withTrashed() for historical display. For converted invoices the customer is
 * copied from the source quotation — a request can never substitute another
 * customer's id.
 *
 * quotation_id records the source quotation when this invoice was created by
 * conversion (or NULL for a directly-created invoice) and is enforced as unique
 * at the database level, making "one quotation -> at most one invoice" a real
 * constraint — a quotation cannot be converted twice even under concurrency.
 * The relation is resolved with withTrashed() so a soft-deleted quotation still
 * renders on the historical invoice.
 *
 * Lifecycle (see DECISIONS): draft invoices are editable/deletable; sent
 * invoices are finalized and immutable. Payments never apply to a draft, and
 * once a payment is recorded the invoice becomes partially_paid or paid (both
 * terminal and non-editable like sent). Batch 16 adds the synchronized
 * amount_paid / amount_due caches, recomputed by PaymentService from the
 * active-payment aggregate; they are never write targets (not fillable).
 *
 * Deletion is a soft delete; numbers are never reused.
 */
class Invoice extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'date',
        'status',
        'discount_type',
        'discount_amount',
        'notes',
        'currency_code',
        'exchange_rate',
        'base_amount',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'status' => InvoiceStatus::class,
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'amount_paid' => 'decimal:4',
            'amount_due' => 'decimal:4',
            'base_amount' => 'decimal:4',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The historical customer. Soft-deleted customers are kept so invoice
     * history renders; current pickers exclude deleted rows at validation.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * The source quotation when this invoice was produced by conversion, or a
     * null relation for directly-created invoices. withTrashed() keeps the
     * reference renderable on historical invoices.
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    /**
     * Every allocation row pointing at this invoice (active and reversed).
     * PaymentService reconciles amount_paid from the ACTIVE subset.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * Every payment ever allocated to this invoice, through the allocation
     * table (reversed rows included — financial history is never hidden).
     */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(
            Payment::class,
            PaymentAllocation::class,
            'invoice_id',   // FK on payment_allocations
            'id',           // FK on payments
            'id',           // FK on invoices
            'payment_id'    // FK on payment_allocations referencing payments
        );
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
