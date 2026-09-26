<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['fa', 'ar']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('payroll.payslip') }} — {{ $employee->name }}</title>
    <style>
        body{font-family:Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:32px}
        .sheet{max-width:860px;margin:auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:32px}
        .top{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #e2e8f0;padding-bottom:20px;margin-bottom:20px}
        h1{margin:0;font-size:24px} .muted{color:#64748b;font-size:13px} .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px 28px}
        .row{display:flex;justify-content:space-between;gap:16px;padding:9px 0;border-bottom:1px solid #f1f5f9;font-size:14px}
        .total{font-size:18px;font-weight:700;border-top:2px solid #0f172a;margin-top:12px;padding-top:14px}
        .actions{max-width:860px;margin:0 auto 16px;display:flex;justify-content:flex-end;gap:8px}
        button{border:0;border-radius:7px;padding:9px 14px;background:#2563eb;color:white;font-weight:700;cursor:pointer}
        @media print{body{background:#fff;padding:0}.sheet{border:0;border-radius:0;max-width:none}.actions{display:none}}
    </style>
</head>
<body>
<div class="actions">
    <button onclick="window.print()">{{ __('payroll.print_payslip') }}</button>
</div>
<div class="sheet">
    <div class="top">
        <div>
            <h1>{{ __('payroll.payslip') }}</h1>
            <div class="muted">{{ $run->number }} · {{ $run->period_start->toDateString() }} → {{ $run->period_end->toDateString() }}</div>
        </div>
        <div style="text-align:end">
            <strong>{{ $employee->name }}</strong>
            <div class="muted">{{ $employee->employee_code }}</div>
            @if($employee->department)<div class="muted">{{ $employee->department }} @if($employee->job_title) · {{ $employee->job_title }} @endif</div>@endif
        </div>
    </div>

    <div class="grid">
        <div>
            <div class="row"><span>{{ __('payroll.payroll_type') }}</span><strong>{{ __('payroll.'.$line->payroll_type) }}</strong></div>
            <div class="row"><span>{{ __('payroll.payroll_rate') }}</span><strong>{{ $line->payroll_rate }}</strong></div>
            <div class="row"><span>{{ __('payroll.scheduled_days') }}</span><strong>{{ $line->scheduled_days }}</strong></div>
            <div class="row"><span>{{ __('payroll.attended_days') }}</span><strong>{{ $line->attended_days }}</strong></div>
            <div class="row"><span>{{ __('payroll.paid_leave_days') }}</span><strong>{{ $line->paid_leave_days }}</strong></div>
            <div class="row"><span>{{ __('payroll.unpaid_leave_days') }}</span><strong>{{ $line->unpaid_leave_days }}</strong></div>
            <div class="row"><span>{{ __('payroll.absent_days') }}</span><strong>{{ $line->absent_days }}</strong></div>
            <div class="row"><span>{{ __('payroll.regular_hours') }}</span><strong>{{ number_format($line->regular_minutes / 60, 2) }}</strong></div>
            <div class="row"><span>{{ __('payroll.overtime_hours') }}</span><strong>{{ number_format($line->overtime_minutes / 60, 2) }}</strong></div>
        </div>
        <div>
            <div class="row"><span>{{ __('payroll.base_pay') }}</span><strong>{{ $line->base_pay }}</strong></div>
            <div class="row"><span>{{ __('payroll.overtime_pay') }}</span><strong>{{ $line->overtime_pay }}</strong></div>
            <div class="row"><span>{{ __('payroll.other_earnings') }}</span><strong>{{ $line->other_earnings }}</strong></div>
            <div class="row"><span>{{ __('payroll.absence_deduction') }}</span><strong>-{{ $line->absence_deduction }}</strong></div>
            <div class="row"><span>{{ __('payroll.other_deductions') }}</span><strong>-{{ $line->other_deductions }}</strong></div>
            <div class="row total"><span>{{ __('payroll.net') }}</span><strong>{{ $line->net_pay }}</strong></div>
        </div>
    </div>

    <p class="muted" style="margin-top:24px">{{ __('payroll.attendance_source_notice') }}</p>
</div>
</body>
</html>
