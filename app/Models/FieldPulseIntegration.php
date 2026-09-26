<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldPulseIntegration extends Model
{
    protected $fillable = [
        'business_id',
        'organization_key',
        'enabled',
        'last_seen_tenant_uuid',
        'last_request_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_request_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
