<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomItem extends Model
{
    protected $fillable = ['material_product_id', 'quantity', 'wastage_percent'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'wastage_percent' => 'decimal:4'];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'material_product_id');
    }
}
