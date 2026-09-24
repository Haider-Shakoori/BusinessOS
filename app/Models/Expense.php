<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Business-owned expense record (Batch 17).
 *
 * Tenancy follows the standard BelongsToBusiness convention: business_id is
 * auto-assigned from the current BusinessContext on creation, is never in
 * $fillable (request-supplied business_id is ignored), and every query is
 * filtered to the current business by the named `business` global scope.
 *
 * expense_number is allocated exclusively by DocumentNumberService
 * (DocumentType::Expense) inside the same transaction as the create, and the
 * database enforces the composite unique constraint on
 * (business_id, expense_number). It is deliberately NOT in $fillable —
 * request-supplied numbers are ignored; numbers are immutable and never reused.
 *
 * category_id is an optional reference into the Batch 11 categories reference
 * data and is the reserved mapping hook for Phase 6 accounting. It may only be
 * an ACTIVE, current-business category (validated at the request layer); the
 * relation resolves to null after a soft-delete (nullOnDelete).
 *
 * amount is DECIMAL(16,4), cast with `decimal:4`, and always handled as an
 * exact decimal string — never FLOAT. No currency/exchange-rate/base-amount
 * columns exist yet by design: multi-currency belongs to Batch 19, and until
 * then an expense is recorded in the business's base currency.
 *
 * receipt_path stores only a generated, sanitized storage path produced by
 * ExpenseService (never the client filename). Deleting an expense is a soft
 * delete: the row and its audit artifact stay intact, normal queries simply no
 * longer see it.
 *
 * created_by records the authenticated author; users are never removed, so the
 * creator is always resolvable (mirrors Invoice::createdBy).
 */
class Expense extends Model
{
    use BelongsToBusiness;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'expense_date',
        'amount',
        'payment_method',
        'reference',
        'vendor',
        'notes',
        'currency_code',
        'exchange_rate',
        'base_amount',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:4',
            'base_amount' => 'decimal:4',
            'payment_method' => PaymentMethod::class,
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * The historical category. A soft-deleted category resolves to null for
     * display while the expense row itself is preserved (nullOnDelete).
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
