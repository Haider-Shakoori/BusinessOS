<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountAdjustmentNote extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'type',
        'invoice_id',
        'purchase_order_id',
        'note_date',
        'amount',
        'base_amount',
        'currency_code',
        'reason',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'note_date' => 'date',
            'amount' => 'decimal:4',
            'base_amount' => 'decimal:4',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
