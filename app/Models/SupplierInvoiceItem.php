<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInvoiceItem extends Model
{
    protected $fillable = [
        'purchase_order_item_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'received_quantity_snapshot',
        'available_quantity_snapshot',
        'unit_cost',
        'line_total',
        'po_unit_cost',
        'po_basis_total',
        'price_variance',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'received_quantity_snapshot' => 'decimal:4',
            'available_quantity_snapshot' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'line_total' => 'decimal:4',
            'po_unit_cost' => 'decimal:4',
            'po_basis_total' => 'decimal:4',
            'price_variance' => 'decimal:4',
        ];
    }

    public function supplierInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
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
