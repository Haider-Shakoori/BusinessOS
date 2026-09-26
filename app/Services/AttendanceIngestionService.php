<?php

namespace App\Services;

use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceEmployee;
use App\Models\AttendanceLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AttendanceIngestionService
{
    /**
     * @param list<array<string, mixed>> $records
     * @return array{accepted: int, duplicates: int, unmapped: int}
     */
    public function ingest(AttendanceDevice $device, array $records): array
    {
        $accepted = 0;
        $duplicates = 0;
        $unmapped = 0;

        foreach ($records as $record) {
            $deviceUserId = trim((string) Arr::get($record, 'device_user_id', Arr::get($record, 'user_id', '')));
            $timestamp = Arr::get($record, 'occurred_at', Arr::get($record, 'timestamp'));

            if ($deviceUserId === '' || ! is_string($timestamp) || trim($timestamp) === '') {
                continue;
            }

            $occurredAt = CarbonImmutable::parse($timestamp, $device->timezone ?: config('app.timezone'));
            $mapping = AttendanceDeviceEmployee::withoutGlobalScope('business')
                ->where('business_id', $device->business_id)
                ->where('attendance_device_id', $device->id)
                ->where('device_user_id', $deviceUserId)
                ->first();

            $externalId = trim((string) Arr::get($record, 'external_id', Arr::get($record, 'id', '')));

            if ($externalId === '') {
                $externalId = hash('sha256', implode('|', [
                    $device->uuid,
                    $deviceUserId,
                    $occurredAt->toIso8601String(),
                    (string) Arr::get($record, 'punch_type', ''),
                    (string) Arr::get($record, 'verification_type', ''),
                ]));
            }

            $exists = AttendanceLog::withoutGlobalScope('business')
                ->where('business_id', $device->business_id)
                ->where('attendance_device_id', $device->id)
                ->where('external_id', $externalId)
                ->exists();

            if ($exists) {
                $duplicates++;

                continue;
            }

            $log = new AttendanceLog();
            $log->forceFill([
                'business_id' => $device->business_id,
                'attendance_device_id' => $device->id,
                'employee_id' => $mapping?->employee_id,
                'device_user_id' => $deviceUserId,
                'external_id' => Str::limit($externalId, 191, ''),
                'occurred_at' => $occurredAt->utc(),
                'punch_type' => Arr::get($record, 'punch_type'),
                'verification_type' => Arr::get($record, 'verification_type', 'unknown'),
                'raw_payload' => $record,
                'received_at' => now(),
            ]);
            $log->save();

            $accepted++;

            if ($mapping === null) {
                $unmapped++;
            }
        }

        if ($accepted > 0) {
            $device->forceFill([
                'last_status' => 'online',
                'last_message' => __('attendance.status.logs_received', ['count' => $accepted]),
                'last_seen_at' => now(),
                'last_sync_at' => now(),
            ])->save();
        }

        return compact('accepted', 'duplicates', 'unmapped');
    }
}
