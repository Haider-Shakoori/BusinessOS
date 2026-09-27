<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JournalLine extends Model
{
    protected $fillable = ['account_id', 'cost_center_id', 'debit', 'credit', 'memo'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:4', 'credit' => 'decimal:4'];
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
    public function reconciliationMatch(): HasOne
    {
        return $this->hasOne(BankReconciliationMatch::class);
    }
}
