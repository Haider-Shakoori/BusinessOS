<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsolidationElimination extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'group_key',
        'reference',
        'effective_date',
        'description',
        'status',
        'created_by',
        'reversed_at',
        'reversed_by',
    ];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'reversed_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ConsolidationEliminationLine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
