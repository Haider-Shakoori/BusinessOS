<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invoice line item (Batch 15).
 *
 * Item rows belong exclusively to their parent invoice, so there is NO
 * BelongsToBusiness trait and NO business_id column: tenancy flows through the
 * parent Invoice row.
 *
 * Items are point-in-time snapshots — description, quantity, unit_price, and
 * the tax_rate captured from the Tax row the moment the invoice was written —
 * and are never recomputed from the current product/tax state. When the
 * invoice was converted from a quotation, these snapshots are copied verbatim
 * from the quotation items (no-drift conversion); they are never re-priced
 * from live master data.
 *
 * Items have NO soft-delete column (mirrors quotation_items): deleting an
 * invoice soft-deletes only the header, and the item rows stay behind as the
 * historical snapshot attached to the trashed header (reachable via the
 * header's withTrashed() queries). Hard-deleting a header would cascade the
 * items through the invoice_id FK.
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
class InvoiceItem extends Model
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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
