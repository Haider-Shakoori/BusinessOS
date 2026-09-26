<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HrLeave;
use App\Models\PayrollAdjustment;
use App\Models\PayrollRun;
use App\Services\BusinessContext;
use App\Services\PayrollAttendanceService;
use App\Services\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class HrPayrollController extends Controller
{
    public function index(Request $request, PayrollAttendanceService $attendance): View
    {
        $from = $request->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString();
        $to = $request->date('to')?->toDateString() ?? now()->endOfMonth()->toDateString();

        $employees = Employee::query()->orderBy('employee_code')->get();
        $attendancePreview = $employees
            ->where('is_active', true)
            ->mapWithKeys(fn (Employee $employee): array => [
                $employee->id => $attendance->summary($employee, $from, $to),
            ]);

        return view('hr.index', [
            'employees' => $employees,
            'attendancePreview' => $attendancePreview,
            'leaves' => HrLeave::query()->with('employee')->latest('start_date')->limit(30)->get(),
            'adjustments' => PayrollAdjustment::query()->with('employee')->latest('effective_date')->limit(30)->get(),
            'runs' => PayrollRun::query()->withCount('lines')->latest('period_end')->limit(24)->get(),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function storeEmployee(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $this->employeeData($request, $context);

        Employee::create($data + ['is_active' => true]);

        return back()->with('status', __('payroll.messages.employee_created'));
    }

    public function updateEmployee(Request $request, Employee $employee, BusinessContext $context): RedirectResponse
    {
        $employee->update($this->employeeData($request, $context, $employee));

        return back()->with('status', __('payroll.messages.employee_updated'));
    }

    public function storeLeave(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('business_id', $context->currentId())],
            'leave_type' => ['required', 'string', 'max:50'],
            'is_paid' => ['required', 'boolean'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        HrLeave::create($data + [
            'status' => 'approved',
            'approved_by' => Auth::id(),
        ]);

        return back()->with('status', __('payroll.messages.leave_created'));
    }

    public function storeAdjustment(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('business_id', $context->currentId())],
            'effective_date' => ['required', 'date'],
            'type' => ['required', Rule::in(['earning', 'deduction'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'label' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        PayrollAdjustment::create($data);

        return back()->with('status', __('payroll.messages.adjustment_created'));
    }

    public function generate(Request $request, PayrollService $payroll): RedirectResponse
    {
        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
        ]);

        try {
            $run = $payroll->generate($data['period_start'], $data['period_end']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['payroll' => $exception->getMessage()]);
        }

        return redirect()->route('hr.payroll.show', $run)
            ->with('status', __('payroll.messages.run_generated'));
    }

    public function show(PayrollRun $payrollRun): View
    {
        return view('hr.payroll-show', [
            'run' => $payrollRun->load(['lines.employee', 'journalEntry.lines.account', 'finalizedBy']),
        ]);
    }

    public function finalize(PayrollRun $payrollRun, PayrollService $payroll): RedirectResponse
    {
        try {
            $payroll->finalize($payrollRun, (int) Auth::id());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['payroll' => $exception->getMessage()]);
        }

        return back()->with('status', __('payroll.messages.run_finalized'));
    }

    public function payslip(PayrollRun $payrollRun, Employee $employee): View
    {
        $line = $payrollRun->lines()->where('employee_id', $employee->id)->firstOrFail();

        return view('hr.payslip', [
            'run' => $payrollRun,
            'line' => $line,
            'employee' => $employee,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeData(Request $request, BusinessContext $context, ?Employee $employee = null): array
    {
        $data = $request->validate([
            'employee_code' => [
                'required',
                'string',
                'max:80',
                Rule::unique('employees', 'employee_code')
                    ->where('business_id', $context->currentId())
                    ->ignore($employee?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'hire_date' => ['nullable', 'date'],
            'payroll_type' => ['required', Rule::in(['monthly', 'daily', 'hourly'])],
            'payroll_rate' => ['required', 'numeric', 'min:0'],
            'standard_daily_minutes' => ['required', 'integer', 'between:60,1440'],
            'overtime_rate' => ['nullable', 'numeric', 'min:0'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['required', 'integer', 'between:1,7'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['working_days'] = array_values(array_unique(array_map('intval', $data['working_days'])));
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }
}
