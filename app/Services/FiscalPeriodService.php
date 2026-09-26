<?php

namespace App\Services;

use App\Models\FiscalPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class FiscalPeriodService
{
    public function assertPostingAllowed(string $date): void
    {
        $day = CarbonImmutable::parse($date)->toDateString();

        $closed = FiscalPeriod::query()
            ->where('status', 'closed')
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->exists();

        if ($closed) {
            throw ValidationException::withMessages([
                'entry_date' => __('operations.accounting.period_closed_error'),
            ]);
        }
    }

    public function create(string $name, string $startDate, string $endDate): FiscalPeriod
    {
        if ($startDate > $endDate) {
            throw new RuntimeException('Fiscal period start date must be before its end date.');
        }

        $overlaps = FiscalPeriod::query()
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->exists();

        if ($overlaps) {
            throw new RuntimeException('Fiscal periods cannot overlap.');
        }

        return FiscalPeriod::create([
            'name' => $name,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'open',
        ]);
    }

    public function close(FiscalPeriod $period, int $userId, ?string $note = null): FiscalPeriod
    {
        return DB::transaction(function () use ($period, $userId, $note): FiscalPeriod {
            $locked = FiscalPeriod::query()->lockForUpdate()->findOrFail($period->id);

            if ($locked->status === 'closed') {
                return $locked;
            }

            $locked->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closed_by' => $userId,
                'close_note' => $note,
            ]);

            return $locked->refresh();
        });
    }

    public function reopen(FiscalPeriod $period): FiscalPeriod
    {
        $period->update([
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null,
            'close_note' => null,
        ]);

        return $period->refresh();
    }
}
