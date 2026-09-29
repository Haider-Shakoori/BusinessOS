<?php

namespace App\Models;

use App\Services\WarehouseLocationService;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'warehouse_id', 'location_id', 'product_id', 'product_variant_id', 'type', 'quantity', 'unit_cost',
        'reference_type', 'reference_id', 'note', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $movement): void {
            if ($movement->warehouse_id === null) {
                return;
            }

            $location = app(WarehouseLocationService::class)->forMovement(
                (int) $movement->warehouse_id,
                $movement->location_id !== null ? (int) $movement->location_id : null,
            );

            $movement->location_id = $location->id;
        });
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'location_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
