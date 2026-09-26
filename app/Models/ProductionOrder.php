<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionOrder extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'bom_id', 'product_id', 'number', 'status', 'planned_quantity',
        'actual_quantity', 'start_date', 'due_date', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'planned_quantity' => 'decimal:4',
            'actual_quantity' => 'decimal:4',
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
