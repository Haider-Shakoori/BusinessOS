<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Business-owned customer registry record (Batch 10).
 *
 * Tenancy follows the standard BelongsToBusiness convention: business_id is
 * auto-assigned from the current BusinessContext on creation, is never in
 * $fillable (request-supplied business_id is ignored), and every query is
 * filtered to the current business by the named `business` global scope.
 * Cross-business lookups therefore resolve to nothing (404), never to a row.
 *
 * Deletion is a soft delete (roadmap Batch 10): the row is retained so future
 * financial records referencing the customer keep valid foreign keys.
 *
 * No ledger/balance aggregates live here — money data is computed by later
 * batches (invoices, payments, customer ledger), not stored on this model.
 *
 * Batch 18 adds the two opening-balance fields: a DECIMAL(16,4),
 * non-negative opening_balance representing money the customer owes at the
 * start, and an optional opening_balance_date for chronological ledger
 * placement. Both are plain registry fields; the ledger itself remains a
 * calculated read model over invoices and active payment allocations.
 */
class Customer extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * business_id is intentionally absent: tenant ownership is resolved from
     * the trusted current business context, never from the request.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'company_name',
        'email',
        'phone',
        'address',
        'notes',
        'opening_balance',
        'opening_balance_date',
    ];

    /**
     * The business this customer belongs to.
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Invoices issued to this customer (excludes soft-deleted drafts by the
     * SoftDeletes scope; the ledger service further excludes `draft` rows).
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:4',
            'opening_balance_date' => 'date',
        ];
    }
}
