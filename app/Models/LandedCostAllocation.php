<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandedCostAllocation extends Model
{
    protected $fillable = [
        'goods_receipt_item_id',
        'basis_amount',
        'allocated_amount',
        'unit_cost_increment',
        'final_unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'basis_amount' => 'decimal:4',
            'allocated_amount' => 'decimal:4',
            'unit_cost_increment' => 'decimal:4',
            'final_unit_cost' => 'decimal:4',
        ];
    }

    public function landedCost(): BelongsTo
    {
        return $this->belongsTo(LandedCost::class);
    }

    public function goodsReceiptItem(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptItem::class);
    }
}
