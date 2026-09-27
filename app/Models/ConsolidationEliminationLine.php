<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsolidationEliminationLine extends Model
{
    protected $fillable = ['statement_type', 'debit', 'credit', 'memo'];

    protected function casts(): array
    {
        return ['debit' => 'decimal:4', 'credit' => 'decimal:4'];
    }

    public function elimination(): BelongsTo
    {
        return $this->belongsTo(ConsolidationElimination::class, 'consolidation_elimination_id');
    }
}
