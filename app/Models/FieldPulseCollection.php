<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldPulseCollection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
            'amount' => 'decimal:4',
            'within_geofence' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }
}
