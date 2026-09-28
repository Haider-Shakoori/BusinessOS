<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReorderRule extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'warehouse_id',
        'product_id',
        'product_variant_id',
        'stock_key',
        'reorder_point',
        'target_stock',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'reorder_point' => 'decimal:4',
            'target_stock' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
