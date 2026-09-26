<?php

namespace App\Console\Commands;

use App\Models\AttendanceDevice;
use App\Models\Setting;
use App\Services\AttendanceDeviceConnectionService;
use Illuminate\Console\Command;

class CheckAttendanceDevices extends Command
{
    protected $signature = 'attendance:devices:check';

    protected $description = 'Check enabled pull-capable attendance devices and refresh their connection status.';

    public function handle(AttendanceDeviceConnectionService $connections): int
    {
        $businessIds = Setting::query()
            ->where('group', 'attendance')
            ->where('key', 'enabled')
            ->where('value', '1')
            ->pluck('business_id');

        if ($businessIds->isEmpty()) {
            return self::SUCCESS;
        }

        $intervals = Setting::query()
            ->whereIn('business_id', $businessIds)
            ->where('group', 'attendance')
            ->where('key', 'auto_sync_minutes')
            ->pluck('value', 'business_id');

        $devices = AttendanceDevice::withoutGlobalScope('business')
            ->whereIn('business_id', $businessIds)
            ->where('enabled', true)
            ->get();

        foreach ($devices as $device) {
            $transport = config('attendance.connections.'.$device->connection_type.'.transport');
            $interval = max(1, (int) ($intervals[$device->business_id] ?? 5));

            if ($device->last_sync_at !== null && $device->last_sync_at->diffInMinutes(now()) < $interval) {
                continue;
            }

            if ($transport === 'push') {
                if ($device->last_seen_at !== null && $device->last_seen_at->diffInMinutes(now()) > max(15, $interval * 3)) {
                    $device->forceFill([
                        'last_status' => 'stale',
                        'last_message' => __('attendance.status.push_stale'),
                    ])->save();
                }

                continue;
            }

            $connections->test($device);
            $device->forceFill(['last_sync_at' => now()])->save();
        }

        return self::SUCCESS;
    }
}
