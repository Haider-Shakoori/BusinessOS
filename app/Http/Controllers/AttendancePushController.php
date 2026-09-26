<?php

namespace App\Http\Controllers;

use App\Models\AttendanceDevice;
use App\Services\AttendanceIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendancePushController extends Controller
{
    public function store(Request $request, AttendanceDevice $attendanceDevice, AttendanceIngestionService $ingestion): JsonResponse
    {
        abort_unless($attendanceDevice->enabled, 404);

        $token = $request->bearerToken() ?: $request->header('X-Attendance-Token');

        abort_unless(
            is_string($token)
            && $token !== ''
            && is_string($attendanceDevice->push_token)
            && hash_equals($attendanceDevice->push_token, $token),
            401,
        );

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

        return response()->json([
            'ok' => true,
            ...$result,
        ]);
    }
}
