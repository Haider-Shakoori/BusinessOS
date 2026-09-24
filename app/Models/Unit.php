<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Business-owned unit of measure (Batch 11).
 *
 * Tenancy follows the standard BelongsToBusiness convention: business_id is
 * auto-assigned from the current BusinessContext on creation, is never in
 * $fillable (request-supplied business_id is ignored), and every query is
 * filtered to the current business by the named `business` global scope.
 *
 * Deletion is a soft delete: the row stays so future products that reference
 * the unit keep valid foreign keys, while normal queries no longer see it.
 *
 * No conversion hierarchy exists yet — a unit is a plain lookup label carrying
 * a display name and an optional short symbol (pcs, kg, h).
 */
class Unit extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'short_name',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
