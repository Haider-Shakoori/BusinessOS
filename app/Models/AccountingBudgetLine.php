<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingBudgetLine extends Model
{
    protected $fillable = ['account_id', 'cost_center_id', 'amount', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(AccountingBudget::class, 'accounting_budget_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
