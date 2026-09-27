<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'supplier_id', 'source_supplier_quotation_id', 'number', 'status', 'order_date', 'expected_date',
        'subtotal', 'total', 'notes', 'issued_by', 'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date',
            'expected_date' => 'date',
            'subtotal' => 'decimal:4',
            'total' => 'decimal:4',
            'issued_at' => 'datetime',
        ];
    }

    public function sourceQuotation(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotation::class, 'source_supplier_quotation_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }
}
