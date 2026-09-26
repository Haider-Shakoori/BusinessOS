<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class AttendanceDevice extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'brand',
        'model',
        'connection_type',
        'host',
        'port',
        'base_url',
        'username',
        'password',
        'api_key',
        'serial_number',
        'timezone',
        'tls_verify',
        'timeout_seconds',
        'connection_config',
        'enabled',
    ];

    protected $hidden = ['password', 'api_key', 'push_token'];

    protected static function booted(): void
    {
        static::creating(function (self $device): void {
            $device->uuid ??= (string) Str::uuid();
            $device->push_token ??= Str::random(64);
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'api_key' => 'encrypted',
            'push_token' => 'encrypted',
            'connection_config' => 'array',
            'tls_verify' => 'boolean',
            'enabled' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
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

    public function logs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function employeeMappings(): HasMany
    {
        return $this->hasMany(AttendanceDeviceEmployee::class);
    }
}
