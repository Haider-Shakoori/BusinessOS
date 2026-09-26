<?php

namespace App\Http\Controllers;

use App\Models\AttendanceBridge;
use App\Models\AttendanceBridgeJob;
use App\Models\AttendanceDevice;
use App\Models\Setting;
use App\Services\AttendanceDeviceDiscoveryService;
use App\Services\AttendanceIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceBridgeApiController extends Controller
{
    public function heartbeat(Request $request, string $bridge): JsonResponse
    {
        $attendanceBridge = $this->authenticate($request, $bridge);

        $data = $request->validate([
            'hostname' => ['nullable', 'string', 'max:255'],
            'local_ips' => ['nullable', 'array', 'max:32'],
            'local_ips.*' => ['ip'],
            'version' => ['nullable', 'string', 'max:50'],
        ]);

        $attendanceBridge->forceFill([
            'hostname' => $data['hostname'] ?? $attendanceBridge->hostname,
            'local_ips' => $data['local_ips'] ?? $attendanceBridge->local_ips,
            'version' => $data['version'] ?? $attendanceBridge->version,
            'status' => 'online',
            'last_seen_at' => now(),
        ])->save();

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'pending_jobs' => AttendanceBridgeJob::withoutGlobalScope('business')
                ->where('attendance_bridge_id', $attendanceBridge->id)
                ->where('status', 'pending')
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->count(),
        ]);
    }

    public function jobs(Request $request, string $bridge): JsonResponse
    {
        $attendanceBridge = $this->authenticate($request, $bridge);

        $jobs = AttendanceBridgeJob::withoutGlobalScope('business')
            ->where('attendance_bridge_id', $attendanceBridge->id)
            ->whereIn('status', ['pending', 'claimed'])
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderBy('id')
            ->limit(10)
            ->get();

        foreach ($jobs as $job) {
            if ($job->status === 'pending') {
                $job->forceFill([
                    'status' => 'claimed',
                    'claimed_at' => now(),
                ])->save();
            }
        }

        $this->touch($attendanceBridge);

        return response()->json([
            'ok' => true,
            'jobs' => $jobs->map(fn (AttendanceBridgeJob $job): array => [
                'id' => $job->uuid,
                'type' => $job->type,
                'payload' => $job->payload,
                'expires_at' => $job->expires_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function jobResult(
        Request $request,
        string $bridge,
        string $job,
        AttendanceDeviceDiscoveryService $discovery,
    ): JsonResponse {
        $attendanceBridge = $this->authenticate($request, $bridge);

        $bridgeJob = AttendanceBridgeJob::withoutGlobalScope('business')
            ->where('attendance_bridge_id', $attendanceBridge->id)
            ->where('uuid', $job)
            ->firstOrFail();

        $data = $request->validate([
            'ok' => ['required', 'boolean'],
            'error' => ['nullable', 'string', 'max:4000'],
            'open_ports' => ['nullable', 'array', 'max:32'],
            'open_ports.*' => ['integer', 'between:1,65535'],
            'http_evidence' => ['nullable', 'array', 'max:32'],
            'http_evidence.*.scheme' => ['required_with:http_evidence', 'in:http,https'],
            'http_evidence.*.port' => ['required_with:http_evidence', 'integer', 'between:1,65535'],
            'http_evidence.*.status' => ['required_with:http_evidence', 'integer', 'between:100,599'],
            'http_evidence.*.text' => ['nullable', 'string', 'max:20000'],
            'http_evidence.*.isapi' => ['nullable', 'boolean'],
        ]);

        if (! $data['ok']) {
            $bridgeJob->forceFill([
                'status' => 'failed',
                'error' => $data['error'] ?? __('attendance.discovery.failed'),
                'completed_at' => now(),
            ])->save();

            $this->touch($attendanceBridge);

            return response()->json(['ok' => true]);
        }

        $result = match ($bridgeJob->type) {
            'detect' => $discovery->classify(
                (string) ($bridgeJob->payload['ip'] ?? ''),
                array_values($data['open_ports'] ?? []),
                array_values($data['http_evidence'] ?? []),
            ),
            default => [],
        };

        $result['bridge_id'] = $attendanceBridge->id;
        $result['bridge_uuid'] = $attendanceBridge->uuid;
        $result['bridge_name'] = $attendanceBridge->name;

        $bridgeJob->forceFill([
            'status' => 'completed',
            'result' => $result,
            'error' => null,
            'completed_at' => now(),
        ])->save();

        $this->touch($attendanceBridge);

        return response()->json(['ok' => true]);
    }

    public function devices(Request $request, string $bridge): JsonResponse
    {
        $attendanceBridge = $this->authenticate($request, $bridge);

        $integrationEnabled = Setting::query()
            ->where('business_id', $attendanceBridge->business_id)
            ->where('group', 'attendance')
            ->where('key', 'enabled')
            ->where('value', '1')
            ->exists();

        $devices = AttendanceDevice::withoutGlobalScope('business')
            ->where('business_id', $attendanceBridge->business_id)
            ->where('attendance_bridge_id', $attendanceBridge->id)
            ->where('enabled', true)
            ->orderBy('id')
            ->get();

        $this->touch($attendanceBridge);

        return response()->json([
            'ok' => true,
            'attendance_enabled' => $integrationEnabled,
            'devices' => $devices->map(fn (AttendanceDevice $device): array => [
                'id' => $device->uuid,
                'name' => $device->name,
                'brand' => $device->brand,
                'model' => $device->model,
                'connection_type' => $device->connection_type,
                'host' => $device->host,
                'port' => $device->port,
                'base_url' => $device->base_url,
                'username' => $device->username,
                'password' => $device->password,
                'api_key' => $device->api_key,
                'serial_number' => $device->serial_number,
                'timezone' => $device->timezone,
                'timeout_seconds' => $device->timeout_seconds,
                'connection_config' => $device->connection_config,
            ])->values(),
        ]);
    }

    public function records(
        Request $request,
        string $bridge,
        string $device,
        AttendanceIngestionService $ingestion,
    ): JsonResponse {
        $attendanceBridge = $this->authenticate($request, $bridge);

        $attendanceDevice = AttendanceDevice::withoutGlobalScope('business')
            ->where('business_id', $attendanceBridge->business_id)
            ->where('attendance_bridge_id', $attendanceBridge->id)
            ->where('uuid', $device)
            ->where('enabled', true)
            ->firstOrFail();

        $integrationEnabled = Setting::query()
            ->where('business_id', $attendanceBridge->business_id)
            ->where('group', 'attendance')
            ->where('key', 'enabled')
            ->where('value', '1')
            ->exists();

        abort_unless($integrationEnabled, 404);

        $validated = $request->validate([
            'records' => ['required', 'array', 'min:1', 'max:1000'],
            'records.*.device_user_id' => ['nullable', 'string', 'max:100'],
            'records.*.user_id' => ['nullable', 'string', 'max:100'],
            'records.*.occurred_at' => ['nullable', 'date'],
            'records.*.timestamp' => ['nullable', 'date'],
            'records.*.external_id' => ['nullable', 'string', 'max:191'],
            'records.*.id' => ['nullable', 'string', 'max:191'],
            'records.*.punch_type' => ['nullable', 'string', 'max:30'],
            'records.*.verification_type' => ['nullable', 'string', 'max:30'],
        ]);

        $result = $ingestion->ingest($attendanceDevice, $validated['records']);
        $this->touch($attendanceBridge);

        return response()->json([
            'ok' => true,
            ...$result,
        ]);
    }

    private function authenticate(Request $request, string $uuid): AttendanceBridge
    {
        $bridge = AttendanceBridge::withoutGlobalScope('business')
            ->where('uuid', $uuid)
            ->firstOrFail();

        $token = $request->bearerToken() ?: $request->header('X-Attendance-Bridge-Token');

        abort_unless(
            is_string($token)
            && $token !== ''
            && is_string($bridge->token)
            && hash_equals($bridge->token, $token),
            401,
        );

        return $bridge;
    }

    private function touch(AttendanceBridge $bridge): void
    {
        $bridge->forceFill([
            'status' => 'online',
            'last_seen_at' => now(),
        ])->save();
    }
}
