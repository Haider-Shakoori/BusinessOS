<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-business exchange rate (Batch 19).
 *
 * Owns one currency/business/date branch: the number of BASE currency units
 * that buys ONE unit of the transaction currency (1 USD = 71.50000000 AFN when
 * the base is AFN). Tenancy follows the standard BelongsToBusiness convention —
 * business_id is auto-assigned from BusinessContext on creation, is never in
 * $fillable, and every query is scoped to the current business, so cross-
 * business rates can never leak.
 *
 * effective_date pins the branch to a day: document creation prices with the
 * newest branch at/before the document's own date, giving every document a
 * permanent snapshot that later rate edits never rewrite. The database unique
 * (business_id, currency_code, effective_date) backstops one branch per day.
 */
class ExchangeRate extends Model
{
    use BelongsToBusiness;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'currency_code',
        'rate',
        'effective_date',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'rate' => 'decimal:8',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The referenced global currency (shared registry).
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
