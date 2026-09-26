<?php

namespace App\Services;

use App\Models\AttendanceLog;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class PayrollAttendanceService
{
    public function __construct(private readonly BusinessSettings $settings) {}

    /**
     * Attendance is the payroll time source. Salary, deduction and overtime
     * formulas remain payroll policy; this service supplies one normalized,
     * auditable set of time metrics so payroll cannot diverge from the machine.
     *
     * @return array<string, int|string|null>
     */
    public function summary(Employee $employee, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $timezone = (string) $this->settings->get('regional.timezone', config('app.timezone'));
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($to, $timezone)->endOfDay()->utc();

        $logs = AttendanceLog::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('occurred_at', [$start, $end])
            ->orderBy('occurred_at')
            ->get();

        $days = $logs->groupBy(fn (AttendanceLog $log): string => $log->occurred_at->timezone($timezone)->toDateString());

        $workedMinutes = 0;
        $missingCheckoutDays = 0;

        foreach ($days as $dayLogs) {
            /** @var Collection<int, AttendanceLog> $dayLogs */
            $ordered = $dayLogs->values();

            if ($ordered->count() % 2 !== 0) {
                $missingCheckoutDays++;
            }

            for ($index = 0; $index + 1 < $ordered->count(); $index += 2) {
                $checkIn = $ordered[$index]->occurred_at;
                $checkOut = $ordered[$index + 1]->occurred_at;

                if ($checkOut->greaterThan($checkIn)) {
                    $workedMinutes += (int) floor($checkIn->diffInMinutes($checkOut));
                }
            }
        }

        return [
            'employee_id' => $employee->id,
            'employee_code' => $employee->employee_code,
            'period_start' => CarbonImmutable::parse($from, $timezone)->toDateString(),
            'period_end' => CarbonImmutable::parse($to, $timezone)->toDateString(),
            'attended_days' => $days->count(),
            'punch_count' => $logs->count(),
            'worked_minutes' => $workedMinutes,
            'missing_checkout_days' => $missingCheckoutDays,
            'first_punch_at' => $logs->first()?->occurred_at?->toIso8601String(),
            'last_punch_at' => $logs->last()?->occurred_at?->toIso8601String(),
        ];
    }
}
