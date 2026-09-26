<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\Account;
use App\Models\Employee;
use App\Models\HrLeave;
use App\Models\JournalEntry;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PayrollService
{
    public function __construct(
        private readonly PayrollAttendanceService $attendance,
        private readonly DocumentNumberService $numbers,
    ) {
        //
    }

    public function generate(string $from, string $to): PayrollRun
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        if ($end->lessThan($start)) {
            throw new RuntimeException(__('payroll.errors.invalid_period'));
        }

        return DB::transaction(function () use ($start, $end): PayrollRun {
            if (PayrollRun::query()->whereDate('period_start', $start)->whereDate('period_end', $end)->exists()) {
                throw new RuntimeException(__('payroll.errors.period_exists'));
            }

            $run = PayrollRun::create([
                'number' => $this->numbers->next(DocumentType::PayrollRun),
                'period_start' => $start,
                'period_end' => $end,
                'status' => 'draft',
            ]);

            $employees = Employee::query()
                ->where('is_active', true)
                ->where(function ($query) use ($end): void {
                    $query->whereNull('hire_date')->orWhereDate('hire_date', '<=', $end);
                })
                ->orderBy('employee_code')
                ->get();

            $totalGross = 0.0;
            $totalDeductions = 0.0;
            $totalNet = 0.0;

            foreach ($employees as $employee) {
                $line = $this->calculateEmployee($employee, $start, $end);
                $run->lines()->create($line);

                $totalGross += (float) $line['gross_pay'];
                $totalDeductions += (float) $line['absence_deduction'] + (float) $line['other_deductions'];
                $totalNet += (float) $line['net_pay'];
            }

            $run->update([
                'total_gross' => round($totalGross, 4),
                'total_deductions' => round($totalDeductions, 4),
                'total_net' => round($totalNet, 4),
            ]);

            return $run->load('lines.employee');
        });
    }

    public function finalize(PayrollRun $run, int $userId): PayrollRun
    {
        return DB::transaction(function () use ($run, $userId): PayrollRun {
            $locked = PayrollRun::query()->with('lines')->lockForUpdate()->findOrFail($run->id);

            if ($locked->status !== 'draft') {
                throw new RuntimeException(__('payroll.errors.already_finalized'));
            }

            $earnedGross = round((float) $locked->lines->sum(
                fn ($line): float => max(0, (float) $line->gross_pay - (float) $line->absence_deduction)
            ), 4);
            $otherDeductions = round((float) $locked->lines->sum('other_deductions'), 4);
            $net = round((float) $locked->lines->sum('net_pay'), 4);

            $journal = null;

            if ($earnedGross > 0) {
                $accounts = $this->payrollAccounts();

                $journal = JournalEntry::create([
                    'number' => 'J-'.$locked->number,
                    'entry_date' => $locked->period_end,
                    'status' => 'posted',
                    'description' => __('payroll.accounting_description', ['number' => $locked->number]),
                    'source_type' => PayrollRun::class,
                    'source_id' => $locked->id,
                ]);

                $journal->lines()->create([
                    'account_id' => $accounts['expense']->id,
                    'debit' => $earnedGross,
                    'credit' => 0,
                    'memo' => $locked->number,
                ]);

                if ($net > 0) {
                    $journal->lines()->create([
                        'account_id' => $accounts['payable']->id,
                        'debit' => 0,
                        'credit' => $net,
                        'memo' => $locked->number,
                    ]);
                }

                if ($otherDeductions > 0) {
                    $journal->lines()->create([
                        'account_id' => $accounts['deductions']->id,
                        'debit' => 0,
                        'credit' => $otherDeductions,
                        'memo' => $locked->number,
                    ]);
                }
            }

            $locked->update([
                'status' => 'finalized',
                'journal_entry_id' => $journal?->id,
                'finalized_at' => now(),
                'finalized_by' => $userId,
            ]);

            return $locked->refresh()->load(['lines.employee', 'journalEntry.lines.account']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function calculateEmployee(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $effectiveStart = $employee->hire_date && $employee->hire_date->greaterThan($start)
            ? CarbonImmutable::parse($employee->hire_date)
            : $start;

        $workingDays = array_values(array_unique(array_map(
            'intval',
            $employee->working_days ?: config('payroll.default_working_days', [1, 2, 3, 4, 5]),
        )));

        $standardMinutes = max(
            1,
            (int) ($employee->standard_daily_minutes ?: config('payroll.default_standard_daily_minutes', 480)),
        );

        $attendance = $this->attendance->summary($employee, $effectiveStart->toDateString(), $end->toDateString());
        $dailyMinutes = collect($attendance['daily_minutes'] ?? []);
        $attendedDates = $dailyMinutes->keys()->flip();

        $scheduledDates = $this->scheduledDates($effectiveStart, $end, $workingDays);
        [$paidLeaveDates, $unpaidLeaveDates] = $this->leaveDates(
            $employee,
            $effectiveStart,
            $end,
            $workingDays,
            $attendedDates,
        );

        $attendedScheduled = $scheduledDates->filter(fn (string $date): bool => $attendedDates->has($date));
        $absentDates = $scheduledDates
            ->reject(fn (string $date): bool => $attendedDates->has($date))
            ->reject(fn (string $date): bool => $paidLeaveDates->contains($date))
            ->reject(fn (string $date): bool => $unpaidLeaveDates->contains($date))
            ->values();

        $regularMinutes = 0;
        $overtimeMinutes = 0;

        foreach ($dailyMinutes as $date => $minutes) {
            $minutes = max(0, (int) $minutes);
            $day = CarbonImmutable::parse((string) $date);

            if (in_array($day->dayOfWeekIso, $workingDays, true)) {
                $regularMinutes += min($minutes, $standardMinutes);
                $overtimeMinutes += max(0, $minutes - $standardMinutes);
            } else {
                $overtimeMinutes += $minutes;
            }
        }

        $rate = round((float) ($employee->payroll_rate ?? 0), 4);
        $scheduledDays = $scheduledDates->count();
        $paidLeaveDays = $paidLeaveDates->count();
        $unpaidLeaveDays = $unpaidLeaveDates->count();
        $absentDays = $absentDates->count();

        [$basePay, $absenceDeduction] = $this->basePay(
            $employee,
            $rate,
            $effectiveStart,
            $end,
            $workingDays,
            $scheduledDates,
            $attendedScheduled,
            $paidLeaveDates,
            $unpaidLeaveDates,
            $absentDates,
            $regularMinutes,
            $standardMinutes,
        );

        $overtimeRate = $this->overtimeRate(
            $employee,
            $rate,
            $basePay,
            $scheduledDays,
            $standardMinutes,
        );
        $overtimePay = round(($overtimeMinutes / 60) * $overtimeRate, 4);

        $adjustments = PayrollAdjustment::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('effective_date', [$effectiveStart, $end])
            ->get();

        $otherEarnings = round((float) $adjustments->where('type', 'earning')->sum('amount'), 4);
        $otherDeductions = round((float) $adjustments->where('type', 'deduction')->sum('amount'), 4);

        $grossPay = round(max(0, $basePay + $overtimePay + $otherEarnings), 4);
        $netPay = round(max(0, $grossPay - $absenceDeduction - $otherDeductions), 4);

        return [
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'employee_name' => $employee->name,
            'payroll_type' => $employee->payroll_type,
            'payroll_rate' => $rate,
            'scheduled_days' => $scheduledDays,
            'attended_days' => (int) $attendance['attended_days'],
            'paid_leave_days' => $paidLeaveDays,
            'unpaid_leave_days' => $unpaidLeaveDays,
            'absent_days' => $absentDays,
            'worked_minutes' => (int) $attendance['worked_minutes'],
            'regular_minutes' => $regularMinutes,
            'overtime_minutes' => $overtimeMinutes,
            'missing_checkout_days' => (int) $attendance['missing_checkout_days'],
            'base_pay' => $basePay,
            'overtime_pay' => $overtimePay,
            'other_earnings' => $otherEarnings,
            'absence_deduction' => $absenceDeduction,
            'other_deductions' => $otherDeductions,
            'gross_pay' => $grossPay,
            'net_pay' => $netPay,
            'attendance_snapshot' => [
                ...$attendance,
                'working_days' => $workingDays,
                'standard_daily_minutes' => $standardMinutes,
                'scheduled_dates' => $scheduledDates->values()->all(),
                'paid_leave_dates' => $paidLeaveDates->values()->all(),
                'unpaid_leave_dates' => $unpaidLeaveDates->values()->all(),
                'absent_dates' => $absentDates->values()->all(),
            ],
        ];
    }

    /**
     * @param  list<int>  $workingDays
     * @return Collection<int, string>
     */
    private function scheduledDates(CarbonImmutable $start, CarbonImmutable $end, array $workingDays): Collection
    {
        $dates = collect();

        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            if (in_array($date->dayOfWeekIso, $workingDays, true)) {
                $dates->push($date->toDateString());
            }
        }

        return $dates;
    }

    /**
     * @param  list<int>  $workingDays
     * @param  Collection<string, int>  $attendedDates
     * @return array{0: Collection<int, string>, 1: Collection<int, string>}
     */
    private function leaveDates(
        Employee $employee,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $workingDays,
        Collection $attendedDates,
    ): array {
        $paid = collect();
        $unpaid = collect();

        $leaves = HrLeave::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get();

        foreach ($leaves as $leave) {
            $leaveStart = CarbonImmutable::parse($leave->start_date)->max($start);
            $leaveEnd = CarbonImmutable::parse($leave->end_date)->min($end);

            for ($date = $leaveStart; $date->lte($leaveEnd); $date = $date->addDay()) {
                $key = $date->toDateString();

                if (! in_array($date->dayOfWeekIso, $workingDays, true) || $attendedDates->has($key)) {
                    continue;
                }

                ($leave->is_paid ? $paid : $unpaid)->push($key);
            }
        }

        return [$paid->unique()->values(), $unpaid->unique()->values()];
    }

    /**
     * @param  list<int>  $workingDays
     * @return array{0: float, 1: float}
     */
    private function basePay(
        Employee $employee,
        float $rate,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $workingDays,
        Collection $scheduledDates,
        Collection $attendedScheduled,
        Collection $paidLeaveDates,
        Collection $unpaidLeaveDates,
        Collection $absentDates,
        int $regularMinutes,
        int $standardMinutes,
    ): array {
        if ($employee->payroll_type === 'hourly') {
            $paidLeaveMinutes = $paidLeaveDates->count() * $standardMinutes;
            $base = round((($regularMinutes + $paidLeaveMinutes) / 60) * $rate, 4);

            return [$base, 0.0];
        }

        if ($employee->payroll_type === 'daily') {
            $paidDays = $attendedScheduled->count() + $paidLeaveDates->count();

            return [round($paidDays * $rate, 4), 0.0];
        }

        $dateRates = [];

        foreach ($scheduledDates as $dateString) {
            $date = CarbonImmutable::parse($dateString);
            $monthStart = $date->startOfMonth();
            $monthEnd = $date->endOfMonth();
            $monthScheduled = $this->scheduledDates($monthStart, $monthEnd, $workingDays)->count();
            $dateRates[$dateString] = $monthScheduled > 0 ? $rate / $monthScheduled : 0;
        }

        $base = round(array_sum($dateRates), 4);
        $deductionDates = $unpaidLeaveDates->merge($absentDates)->unique();
        $absence = round((float) $deductionDates->sum(
            fn (string $date): float => (float) ($dateRates[$date] ?? 0)
        ), 4);

        return [$base, $absence];
    }

    private function overtimeRate(
        Employee $employee,
        float $rate,
        float $basePay,
        int $scheduledDays,
        int $standardMinutes,
    ): float {
        if ((float) ($employee->overtime_rate ?? 0) > 0) {
            return round((float) $employee->overtime_rate, 4);
        }

        $hours = max($standardMinutes / 60, 0.01);
        $multiplier = (float) config('payroll.overtime_multiplier', 1.5);

        return match ($employee->payroll_type) {
            'hourly' => round($rate * $multiplier, 4),
            'daily' => round(($rate / $hours) * $multiplier, 4),
            default => round(($basePay / max($scheduledDays, 1) / $hours) * $multiplier, 4),
        };
    }

    /**
     * @return array{expense: Account, payable: Account, deductions: Account}
     */
    private function payrollAccounts(): array
    {
        return [
            'expense' => Account::firstOrCreate(
                ['code' => 'PAYROLL-EXP'],
                ['name' => 'Payroll Expense', 'type' => 'expense', 'is_active' => true],
            ),
            'payable' => Account::firstOrCreate(
                ['code' => 'PAYROLL-PAYABLE'],
                ['name' => 'Payroll Payable', 'type' => 'liability', 'is_active' => true],
            ),
            'deductions' => Account::firstOrCreate(
                ['code' => 'PAYROLL-DEDUCT'],
                ['name' => 'Payroll Deductions Payable', 'type' => 'liability', 'is_active' => true],
            ),
        ];
    }
}
