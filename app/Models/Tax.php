<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Business-owned tax definition (Batch 11).
 *
 * Tenancy follows the standard BelongsToBusiness convention: business_id is
 * auto-assigned from the current BusinessContext on creation, is never in
 * $fillable (request-supplied business_id is ignored), and every query is
 * filtered to the current business by the named `business` global scope.
 *
 * `rate` is stored as DECIMAL(8,4) and cast with `decimal:4`, so it is always
 * handled as an exact decimal string — never FLOAT/DOUBLE. This mirrors the
 * approved money-precision decision (DECIMAL(8,4) for tax rates).
 *
 * The route/middleware layer enforces the optional tax feature
 * (general.tax_enabled). This model is definition data only: it never
 * computes tax, and its existence never implies the feature is enabled.
 *
 * Deletion is a soft delete so future documents referencing a rate keep valid
 * foreign keys.
 */
class Tax extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'rate',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
