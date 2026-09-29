<?php

namespace App\Models;

use App\Services\WarehouseLocationService;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosRegister extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['warehouse_id', 'location_id', 'code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $register): void {
            if ($register->warehouse_id === null) {
                return;
            }

            $location = app(WarehouseLocationService::class)->forMovement(
                (int) $register->warehouse_id,
                $register->location_id !== null ? (int) $register->location_id : null,
            );

            $register->location_id = $location->id;
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(PosShift::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(PosSale::class);
    }
}
