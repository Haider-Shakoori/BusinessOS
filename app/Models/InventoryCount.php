<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryCount extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'warehouse_id',
        'number',
        'status',
        'count_date',
        'notes',
        'created_by',
        'submitted_by',
        'approved_by',
        'submitted_at',
        'approved_at',
        'total_positive_variance_value',
        'total_negative_variance_value',
    ];

    protected function casts(): array
    {
        return [
            'count_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'total_positive_variance_value' => 'decimal:4',
            'total_negative_variance_value' => 'decimal:4',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryCountItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
