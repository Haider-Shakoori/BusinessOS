<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quotation line item (Batch 14).
 *
 * Item rows belong exclusively to their parent quotation, so there is NO
 * BelongsToBusiness trait and NO business_id column: tenancy flows through the
 * parent Quotation row. They are point-in-time snapshots — description,
 * quantity, unit_price, and the tax_rate captured from the Tax row the moment
 * the quotation was written — and are never recomputed from the current
 * product/tax state.
 *
 * Items have NO soft-delete column (approved Batch 14 schema): deleting a
 * quotation soft-deletes only the header, and the item rows stay behind as the
 * historical snapshot attached to the trashed header (reachable via the
 * header's withTrashed() queries). Hard-deleting a header would cascade the
 * items through the quotation_id FK.
 *
 * product_id and tax_id are nullable (nullOnDelete) and resolved with
 * withTrashed() for historical display; new selections are restricted to
 * ACTIVE records of the current business at validation time.
 *
 * line_subtotal / line_tax / line_total are 4-dp exact-decimal snapshots
 * produced by the QuotationCalculator and are never accepted from a request.
 * When the optional tax feature is disabled, tax_id is null, tax_rate is null,
 * and line_tax is 0.
 */
class QuotationItem extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'tax_id',
        'tax_rate',
        'line_subtotal',
        'line_tax',
        'line_total',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'line_subtotal' => 'decimal:4',
            'line_tax' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class)->withTrashed();
    }
}
