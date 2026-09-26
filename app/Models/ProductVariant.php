<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    protected $fillable = ['product_id', 'name', 'sku', 'sale_price', 'is_active'];

    protected function casts(): array
    {
        return [
            'sale_price' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function effectiveSalePrice(): string
    {
        return $this->sale_price ?? $this->product->sale_price;
    }
}
