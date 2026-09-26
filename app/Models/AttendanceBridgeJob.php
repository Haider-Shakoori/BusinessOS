<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AttendanceBridgeJob extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'attendance_bridge_id',
        'type',
        'status',
        'payload',
        'result',
        'error',
        'claimed_at',
        'completed_at',
        'expires_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $job): void {
            $job->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function bridge(): BelongsTo
    {
        return $this->belongsTo(AttendanceBridge::class, 'attendance_bridge_id');
    }
}
