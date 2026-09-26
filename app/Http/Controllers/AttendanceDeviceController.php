<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\AttendanceDeviceRequest;
use App\Models\AttendanceDevice;
use App\Models\AttendanceDeviceEmployee;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Services\AttendanceDeviceConnectionService;
use App\Services\AttendanceDeviceDiscoveryService;
use App\Services\BusinessContext;
use App\Services\PayrollAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AttendanceDeviceController extends Controller
{
    public function index(BusinessContext $context, PayrollAttendanceService $payrollAttendance): View
    {
        $employees = Employee::query()->orderBy('name')->get();
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        return view('settings.attendance-devices.index', [
            'business' => $context->current(),
            'brands' => config('attendance.brands', []),
            'connections' => config('attendance.connections', []),
            'devices' => AttendanceDevice::query()->orderBy('name')->get(),
            'employees' => $employees,
            'mappings' => AttendanceDeviceEmployee::query()->with(['device', 'employee'])->orderByDesc('id')->get(),
            'recentLogs' => AttendanceLog::query()->with(['device', 'employee'])->latest('occurred_at')->limit(25)->get(),
            'payrollSummaries' => $employees->map(fn (Employee $employee): array => $payrollAttendance->summary($employee, $periodStart, $periodEnd)),
            'payrollPeriodStart' => $periodStart,
            'payrollPeriodEnd' => $periodEnd,
        ]);
    }

    public function detect(Request $request, AttendanceDeviceDiscoveryService $discovery): JsonResponse
    {
        $data = $request->validate([
            'ip' => ['required', 'ip'],
        ]);

        try {
            return response()->json([
                'ok' => true,
                'result' => $discovery->discover($data['ip']),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return response()->json([
                'ok' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function store(AttendanceDeviceRequest $request): RedirectResponse
    {
        $data = $this->normalized($request->validated());
        AttendanceDevice::create($data);

        return back()->with('status', __('attendance.device_created'));
    }

    public function update(AttendanceDeviceRequest $request, AttendanceDevice $attendanceDevice): RedirectResponse
    {
        $data = $this->normalized($request->validated());

        if (($data['password'] ?? null) === null || $data['password'] === '') {
            unset($data['password']);
        }

        if (($data['api_key'] ?? null) === null || $data['api_key'] === '') {
            unset($data['api_key']);
        }

        $attendanceDevice->update($data);

        return back()->with('status', __('attendance.device_updated'));
    }

    public function destroy(AttendanceDevice $attendanceDevice): RedirectResponse
    {
        $attendanceDevice->delete();

        return back()->with('status', __('attendance.device_deleted'));
    }

    public function testConnection(AttendanceDevice $attendanceDevice, AttendanceDeviceConnectionService $connections): RedirectResponse
    {
        $result = $connections->test($attendanceDevice);

        return back()->with($result['ok'] ? 'status' : 'attendance_error', $result['message']);
    }

    public function storeEmployee(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'employee_code' => ['required', 'string', 'max:80', Rule::unique('employees', 'employee_code')->where('business_id', app(BusinessContext::class)->currentId())],
            'name' => ['required', 'string', 'max:255'],
            'payroll_type' => ['required', Rule::in(['monthly', 'daily', 'hourly'])],
            'payroll_rate' => ['nullable', 'numeric', 'min:0'],
        ]);

        Employee::create($data + ['is_active' => true]);

        return back()->with('status', __('attendance.employee_created'));
    }

    public function mapEmployee(Request $request, AttendanceDevice $attendanceDevice): RedirectResponse
    {
        $employeeId = (int) $request->input('employee_id');
        $existing = AttendanceDeviceEmployee::query()
            ->where('attendance_device_id', $attendanceDevice->id)
            ->where('employee_id', $employeeId)
            ->first();

        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('business_id', app(BusinessContext::class)->currentId())],
            'device_user_id' => [
                'required',
                'string',
                'max:100',
                Rule::unique('attendance_device_employees', 'device_user_id')
                    ->where('attendance_device_id', $attendanceDevice->id)
                    ->ignore($existing?->id),
            ],
        ]);

        AttendanceDeviceEmployee::updateOrCreate(
            [
                'attendance_device_id' => $attendanceDevice->id,
                'employee_id' => $data['employee_id'],
            ],
            ['device_user_id' => $data['device_user_id']],
        );

        return back()->with('status', __('attendance.mapping_saved'));
    }

    private function normalized(array $data): array
    {
        $definition = config('attendance.connections.'.($data['connection_type'] ?? ''), []);

        if (empty($data['port']) && ! empty($definition['default_port'])) {
            $data['port'] = $definition['default_port'];
        }

        $data['timeout_seconds'] = $data['timeout_seconds'] ?? 8;
        $data['timezone'] = $data['timezone'] ?: null;

        return $data;
    }
}
