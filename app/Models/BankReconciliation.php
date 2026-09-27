<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankReconciliation extends Model
{
    use BelongsToBusiness;

    protected $fillable = ['financial_account_id', 'statement_date', 'statement_balance', 'status', 'reconciled_at', 'reconciled_by'];

    protected function casts(): array
    {
        return ['statement_date' => 'date', 'statement_balance' => 'decimal:4', 'reconciled_at' => 'datetime'];
    }

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankReconciliationMatch::class);
    }
}
