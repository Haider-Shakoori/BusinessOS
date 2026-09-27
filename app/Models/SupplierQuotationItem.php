<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierQuotationItem extends Model
{
    protected $fillable = [
        'purchase_requisition_item_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'unit_cost',
        'line_total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotation::class, 'supplier_quotation_id');
    }

    public function requisitionItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisitionItem::class, 'purchase_requisition_item_id');
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
