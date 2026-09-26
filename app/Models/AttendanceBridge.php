<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class AttendanceBridge extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'hostname',
        'local_ips',
        'version',
        'status',
        'last_seen_at',
    ];

    protected $hidden = ['token'];

    protected static function booted(): void
    {
        static::creating(function (self $bridge): void {
            $bridge->uuid ??= (string) Str::uuid();
            $bridge->token ??= Str::random(64);
        });
    }

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'local_ips' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(AttendanceDevice::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(AttendanceBridgeJob::class);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->greaterThan(now()->subMinutes(2));
    }
}
