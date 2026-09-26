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
        'department',
        'job_title',
        'email',
        'phone',
        'hire_date',
        'payroll_type',
        'payroll_rate',
        'standard_daily_minutes',
        'overtime_rate',
        'working_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'payroll_rate' => 'decimal:4',
            'standard_daily_minutes' => 'integer',
            'overtime_rate' => 'decimal:4',
            'working_days' => 'array',
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

    public function leaves(): HasMany
    {
        return $this->hasMany(HrLeave::class);
    }

    public function payrollAdjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }

    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }
}
