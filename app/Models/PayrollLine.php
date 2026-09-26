<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLine extends Model
{
    protected $fillable = [
        'employee_id',
        'employee_code',
        'employee_name',
        'payroll_type',
        'payroll_rate',
        'scheduled_days',
        'attended_days',
        'paid_leave_days',
        'unpaid_leave_days',
        'absent_days',
        'worked_minutes',
        'regular_minutes',
        'overtime_minutes',
        'missing_checkout_days',
        'base_pay',
        'overtime_pay',
        'other_earnings',
        'absence_deduction',
        'other_deductions',
        'gross_pay',
        'net_pay',
        'attendance_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'payroll_rate' => 'decimal:4',
            'base_pay' => 'decimal:4',
            'overtime_pay' => 'decimal:4',
            'other_earnings' => 'decimal:4',
            'absence_deduction' => 'decimal:4',
            'other_deductions' => 'decimal:4',
            'gross_pay' => 'decimal:4',
            'net_pay' => 'decimal:4',
            'attendance_snapshot' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
