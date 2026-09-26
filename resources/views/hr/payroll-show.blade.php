@extends('layouts.app')

@section('content')
<x-app.page icon="banknotes" :title="$run->number" :subtitle="$run->period_start->toDateString().' → '.$run->period_end->toDateString()">
    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif
    @if ($errors->any())
        <div class="mb-5"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div>
    @endif

    <div class="grid gap-4 md:grid-cols-4">
        <x-ui.card><div class="text-xs uppercase tracking-wide text-slate-500">{{ __('payroll.run_status') }}</div><div class="mt-2"><x-ui.badge :tone="$run->status === 'finalized' ? 'success' : 'warning'">{{ __('payroll.'.$run->status) }}</x-ui.badge></div></x-ui.card>
        <x-ui.card><div class="text-xs uppercase tracking-wide text-slate-500">{{ __('payroll.gross') }}</div><div class="mt-2 text-xl font-bold text-slate-900 dark:text-white">{{ $run->total_gross }}</div></x-ui.card>
        <x-ui.card><div class="text-xs uppercase tracking-wide text-slate-500">{{ __('payroll.deductions') }}</div><div class="mt-2 text-xl font-bold text-slate-900 dark:text-white">{{ $run->total_deductions }}</div></x-ui.card>
        <x-ui.card><div class="text-xs uppercase tracking-wide text-slate-500">{{ __('payroll.net') }}</div><div class="mt-2 text-xl font-bold text-slate-900 dark:text-white">{{ $run->total_net }}</div></x-ui.card>
    </div>

    @if ($run->status === 'draft')
        @can('payroll.finalize')
            <div class="mt-5 flex justify-end">
                <form method="POST" action="{{ route('hr.payroll.finalize', $run) }}" onsubmit="return confirm('{{ __('payroll.finalize_confirm') }}')">
                    @csrf
                    <x-ui.button type="submit" icon="check-circle">{{ __('payroll.finalize') }}</x-ui.button>
                </form>
            </div>
        @endcan
    @elseif ($run->journalEntry)
        <div class="mt-5">
            <x-ui.alert type="success">
                {{ __('payroll.accounting_entry') }}: {{ $run->journalEntry->number }}
            </x-ui.alert>
        </div>
    @endif

    <div class="mt-5">
        <x-ui.card>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('payroll.employee') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.scheduled_days') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.attended_days') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.paid_leave_days') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.unpaid_leave_days') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.absent_days') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.regular_hours') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.overtime_hours') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.base_pay') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.overtime_pay') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.other_earnings') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.absence_deduction') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.other_deductions') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.net') }}</x-ui.th>
                            <x-ui.th></x-ui.th>
                        </tr>
                    </x-slot:head>
                    @foreach ($run->lines as $line)
                        <tr>
                            <x-ui.td><div class="font-medium">{{ $line->employee_name }}</div><div class="text-xs text-slate-500">{{ $line->employee_code }}</div></x-ui.td>
                            <x-ui.td>{{ $line->scheduled_days }}</x-ui.td>
                            <x-ui.td>{{ $line->attended_days }}</x-ui.td>
                            <x-ui.td>{{ $line->paid_leave_days }}</x-ui.td>
                            <x-ui.td>{{ $line->unpaid_leave_days }}</x-ui.td>
                            <x-ui.td>{{ $line->absent_days }}</x-ui.td>
                            <x-ui.td>{{ number_format($line->regular_minutes / 60, 2) }}</x-ui.td>
                            <x-ui.td>{{ number_format($line->overtime_minutes / 60, 2) }}</x-ui.td>
                            <x-ui.td>{{ $line->base_pay }}</x-ui.td>
                            <x-ui.td>{{ $line->overtime_pay }}</x-ui.td>
                            <x-ui.td>{{ $line->other_earnings }}</x-ui.td>
                            <x-ui.td>{{ $line->absence_deduction }}</x-ui.td>
                            <x-ui.td>{{ $line->other_deductions }}</x-ui.td>
                            <x-ui.td class="font-semibold">{{ $line->net_pay }}</x-ui.td>
                            <x-ui.td><x-ui.button href="{{ route('hr.payroll.payslip', [$run, $line->employee_id]) }}" size="sm" variant="secondary">{{ __('payroll.payslip') }}</x-ui.button></x-ui.td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>
</x-app.page>
@endsection
