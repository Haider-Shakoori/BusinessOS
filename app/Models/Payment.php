<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * Business-owned payment event (Batch 16).
 *
 * Tenancy follows the standard BelongsToBusiness convention: business_id is
 * auto-assigned from BusinessContext on creation, is never in $fillable
 * (request-supplied business_id is ignored), and every query is filtered to
 * the current business by the named `business` global scope.
 *
 * payment_number is allocated exclusively by DocumentNumberService
 * (DocumentType::Payment) inside the same transaction as the insert, and the
 * database enforces the unique (business_id, payment_number) constraint. It is
 * deliberately NOT in $fillable: a request can never supply or forge it.
 * Numbers are immutable after creation and never reused — reversing a payment
 * marks the row and keeps its number; the row is never deleted and the number
 * is never re-issued.
 *
 * The polymorphic paymentable (paymentable_type/id) plus the dedicated
 * payment_allocations row pin the payment to exactly one invoice per the
 * approved architecture. The Payer party (party_type 'customer' + party_id) is
 * ALWAYS inferred from the invoice at recording time; a request can never
 * substitute it.
 *
 * Reversal is the only writer of the reversed_* columns: reversed_at,
 * reversed_by and reversal_reason are set by PaymentService::reverse inside a
 * transaction that also reconciles the target invoice. An active payment
 * (reversed_at null) counts toward invoices.amount_paid; a reversed one is
 * excluded.
 */
class Payment extends Model
{
    use BelongsToBusiness;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'amount',
        'payment_method',
        'payment_date',
        'reference',
        'notes',
        'currency_code',
        'exchange_rate',
        'base_amount',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:4',
            'base_amount' => 'decimal:4',
            'reversed_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The invoice this payment is recorded against (single allocation per
     * payment). Resolved through the allocation table like the schema itself.
     */
    public function invoice(): HasOneThrough
    {
        return $this->hasOneThrough(
            Invoice::class,
            PaymentAllocation::class,
            'payment_id',   // FK on payment_allocations
            'id',           // FK on invoices
            'id',           // FK on payments
            'invoice_id'    // FK on payment_allocations referencing invoices
        );
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * The authenticated user who recorded the payment. Users are never
     * removed, so no withTrashed() is needed (mirrors Invoice::createdBy).
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
