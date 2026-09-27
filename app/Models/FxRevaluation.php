<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FxRevaluation extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'invoice_id',
        'revaluation_date',
        'currency_code',
        'closing_rate',
        'foreign_balance',
        'historical_base_balance',
        'revalued_base_balance',
        'adjustment',
        'journal_entry_id',
        'reversal_journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'revaluation_date' => 'date',
            'closing_rate' => 'decimal:8',
            'foreign_balance' => 'decimal:4',
            'historical_base_balance' => 'decimal:4',
            'revalued_base_balance' => 'decimal:4',
            'adjustment' => 'decimal:4',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }
}
