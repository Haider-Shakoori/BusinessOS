<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'attendance_device_id',
        'employee_id',
        'device_user_id',
        'external_id',
        'occurred_at',
        'punch_type',
        'verification_type',
        'raw_payload',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'attendance_device_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
