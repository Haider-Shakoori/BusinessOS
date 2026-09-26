<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPosting extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'source_type',
        'source_id',
        'event_key',
        'journal_entry_id',
        'reversal_journal_entry_id',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return ['reversed_at' => 'datetime'];
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
