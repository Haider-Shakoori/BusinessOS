<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'employee_code',
        'name',
        'payroll_type',
        'payroll_rate',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'payroll_rate' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function deviceMappings(): HasMany
    {
        return $this->hasMany(AttendanceDeviceEmployee::class);
    }
}
