<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrLeave extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'employee_id',
        'leave_type',
        'is_paid',
        'start_date',
        'end_date',
        'status',
        'note',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'is_paid' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
