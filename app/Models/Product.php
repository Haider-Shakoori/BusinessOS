<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Business-owned product or service (Batch 12).
 *
 * Products and services share the `products` table; `type` (the ProductType
 * enum) is the discriminator and is cast to and from the enum so unsupported
 * values can never survive a write.
 *
 * Tenancy follows the standard BelongsToBusiness convention: business_id is
 * auto-assigned from the current BusinessContext on creation, is never in
 * $fillable (request-supplied business_id is ignored), and every query is
 * filtered to the current business by the named `business` global scope.
 *
 * `sale_price` is stored as DECIMAL(16,4) and cast with `decimal:4`, so it is
 * always handled as an exact decimal string — never FLOAT/DOUBLE. This mirrors
 * the approved money-precision decision (DECIMAL(16,4) for amounts).
 *
 * category/unit/tax are optional references into the Batch 11 catalog data.
 * They belong to the same business; soft-deleting a referenced master record
 * leaves the product intact (nullOnDelete FK) and the relation resolves to
 * null for display purposes.
 *
 * Deletion is a soft delete: products may be referenced by future documents
 * (quotations/invoices), so rows are never hard-deleted by app code.
 *
 * There is deliberately NO inventory/stock behaviour here — stock, variants,
 * purchasing etc. belong to the dedicated inventory batches.
 */
class Product extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'name',
        'sku',
        'description',
        'category_id',
        'unit_id',
        'tax_id',
        'sale_price',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'sale_price' => 'decimal:4',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
