<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'purchase_order_id',
        'warehouse_id',
        'number',
        'status',
        'receipt_date',
        'total',
        'notes',
        'received_by',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date',
            'total' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }
}
