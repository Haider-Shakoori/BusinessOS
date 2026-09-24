<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single business-scoped settings value (sparse override).
 *
 * Deliberately NOT a tenant-owned model in the UI sense and does NOT apply the
 * BelongsToBusiness global scope: App\Services\BusinessSettings is the one
 * authority that reads and writes this table, and it always supplies
 * business_id from the validated current business. Like Role, the model is
 * reachable only through the business relation + the service, so a global
 * scope would add context-dependence without adding isolation.
 */
class Setting extends Model
{
    protected $fillable = ['business_id', 'group', 'key', 'value', 'type'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
