<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollAdjustment extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'employee_id',
        'effective_date',
        'type',
        'amount',
        'label',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
