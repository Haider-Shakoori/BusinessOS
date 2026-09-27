<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalYearClose extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'net_income',
        'retained_earnings_account_id',
        'journal_entry_id',
        'closed_at',
        'closed_by',
        'close_note',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'net_income' => 'decimal:4',
            'closed_at' => 'datetime',
        ];
    }

    public function retainedEarningsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'retained_earnings_account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
