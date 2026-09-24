<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payment-to-invoice allocation join (Batch 16).
 *
 * Each row allocates a payment's amount to one invoice; Batch 16 creates
 * exactly one row per payment. The table deliberately carries NO business_id:
 * tenancy flows through the owning Payment (and the invoice it references),
 * both of which are already business-owned documents. This is the
 * authoritative financial source that PaymentService aggregates to reconcile
 * invoices.amount_paid — it is never written by a request.
 *
 * Rows are never deleted in practice (payments are only marked reversed and
 * invoices only soft-deleted), so hard-delete FK cascades are unreachable.
 */
class PaymentAllocation extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_id',
        'invoice_id',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * The allocated invoice, kept historical by withTrashed() — a reversal or
     * payment listing must still render the target even after a soft delete.
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withTrashed();
    }
}
