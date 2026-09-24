<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-business enabled currency (Batch 19).
 *
 * A row here records that a business has chosen to use a currency in addition
 * to its base currency (which is configured via the regional.currency setting
 * and always implicitly enabled). business_id is the tenant key and is never
 * accepted from a request — it is assigned from the trusted BusinessContext by
 * the owning controller. No amounts live here.
 */
class BusinessCurrency extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'currency_code',
    ];

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
}
