@extends('layouts.app')

@section('content')
<x-app.page icon="user-group" :title="__('payroll.title')" :subtitle="__('payroll.subtitle')">
    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif

    @if ($errors->any())
        <div class="mb-5"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div>
    @endif

    <x-ui.card>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('payroll.attendance_preview') }}</h2>
                <p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">{{ __('payroll.attendance_source_notice') }}</p>
            </div>
            <form method="GET" action="{{ route('hr.index') }}" class="flex flex-wrap items-end gap-2">
                <x-ui.input name="from" type="date" :label="__('payroll.from')" :value="$from" />
                <x-ui.input name="to" type="date" :label="__('payroll.to')" :value="$to" />
                <x-ui.button type="submit" variant="secondary">{{ __('payroll.apply') }}</x-ui.button>
            </form>
        </div>

        <div class="mt-5 overflow-x-auto">
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('payroll.employee') }}</x-ui.th>
                        <x-ui.th>{{ __('payroll.attended_days') }}</x-ui.th>
                        <x-ui.th>{{ __('payroll.worked_hours') }}</x-ui.th>
                        <x-ui.th>{{ __('payroll.punches') }}</x-ui.th>
                        <x-ui.th>{{ __('payroll.missing_checkout') }}</x-ui.th>
                    </tr>
                </x-slot:head>
                @forelse ($employees->where('is_active', true) as $employee)
                    @php($summary = $attendancePreview[$employee->id] ?? [])
                    <tr>
                        <x-ui.td>
                            <div class="font-medium text-slate-900 dark:text-white">{{ $employee->name }}</div>
                            <div class="text-xs text-slate-500">{{ $employee->employee_code }}</div>
                        </x-ui.td>
                        <x-ui.td>{{ $summary['attended_days'] ?? 0 }}</x-ui.td>
                        <x-ui.td>{{ number_format(((int) ($summary['worked_minutes'] ?? 0)) / 60, 2) }}</x-ui.td>
                        <x-ui.td>{{ $summary['punch_count'] ?? 0 }}</x-ui.td>
                        <x-ui.td>{{ $summary['missing_checkout_days'] ?? 0 }}</x-ui.td>
                    </tr>
                @empty
                    <tr><x-ui.td colspan="5">{{ __('payroll.no_employees') }}</x-ui.td></tr>
                @endforelse
            </x-ui.table>
        </div>
    </x-ui.card>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        @can('hr.manage')
            <x-ui.card>
                <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('payroll.add_employee') }}</h2></x-slot:header>
                <form method="POST" action="{{ route('hr.employees.store') }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    <x-ui.input name="employee_code" :label="__('payroll.employee_code')" required />
                    <x-ui.input name="name" :label="__('payroll.name')" required />
                    <x-ui.input name="department" :label="__('payroll.department')" />
                    <x-ui.input name="job_title" :label="__('payroll.job_title')" />
                    <x-ui.input name="email" type="email" :label="__('payroll.email')" />
                    <x-ui.input name="phone" :label="__('payroll.phone')" />
                    <x-ui.input name="hire_date" type="date" :label="__('payroll.hire_date')" />
                    <x-ui.select name="payroll_type" :label="__('payroll.payroll_type')" required>
                        @foreach (['monthly', 'daily', 'hourly'] as $type)
                            <option value="{{ $type }}">{{ __('payroll.'.$type) }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input name="payroll_rate" type="number" step="0.0001" min="0" :label="__('payroll.payroll_rate')" required />
                    <x-ui.input name="standard_daily_minutes" type="number" min="60" max="1440" value="480" :label="__('payroll.standard_daily_minutes')" required />
                    <div class="sm:col-span-2">
                        <x-ui.input name="overtime_rate" type="number" step="0.0001" min="0" :label="__('payroll.overtime_rate')" />
                        <p class="mt-1 text-xs text-slate-500">{{ __('payroll.overtime_auto') }}</p>
                    </div>
                    <div class="sm:col-span-2">
                        <div class="mb-2 text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('payroll.working_days') }}</div>
                        <div class="flex flex-wrap gap-2">
                            @foreach ([1,2,3,4,5,6,7] as $day)
                                <label class="inline-flex cursor-pointer items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-xs dark:border-slate-700">
                                    <input type="checkbox" name="working_days[]" value="{{ $day }}" @checked(in_array($day, config('payroll.default_working_days', [1,2,3,4,5]), true))>
                                    <span>{{ __('payroll.days.'.$day) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <input type="hidden" name="is_active" value="1">
                    <div class="sm:col-span-2 flex justify-end">
                        <x-ui.button type="submit" icon="plus">{{ __('payroll.add_employee') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endcan

        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('payroll.employees') }}</h2></x-slot:header>
            <div class="space-y-3">
                @forelse ($employees as $employee)
                    <div x-data="{ edit: false }" class="rounded-[9px] border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-semibold text-slate-900 dark:text-white">{{ $employee->name }}</h3>
                                    <x-ui.badge :tone="$employee->is_active ? 'success' : 'neutral'">
                                        {{ $employee->is_active ? __('payroll.active') : __('payroll.inactive') }}
                                    </x-ui.badge>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ $employee->employee_code }}
                                    @if ($employee->department) · {{ $employee->department }} @endif
                                    @if ($employee->job_title) · {{ $employee->job_title }} @endif
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ __('payroll.'.$employee->payroll_type) }} · {{ $employee->payroll_rate }}
                                </p>
                            </div>
                            @can('hr.manage')
                                <x-ui.button type="button" size="sm" variant="secondary" x-on:click="edit = !edit">
                                    {{ __('payroll.edit_employee') }}
                                </x-ui.button>
                            @endcan
                        </div>

                        @can('hr.manage')
                            <form x-show="edit" x-cloak method="POST" action="{{ route('hr.employees.update', $employee) }}" class="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-2 dark:border-slate-700">
                                @csrf
                                @method('PATCH')
                                <x-ui.input name="employee_code" :label="__('payroll.employee_code')" :value="$employee->employee_code" required />
                                <x-ui.input name="name" :label="__('payroll.name')" :value="$employee->name" required />
                                <x-ui.input name="department" :label="__('payroll.department')" :value="$employee->department" />
                                <x-ui.input name="job_title" :label="__('payroll.job_title')" :value="$employee->job_title" />
                                <x-ui.input name="email" type="email" :label="__('payroll.email')" :value="$employee->email" />
                                <x-ui.input name="phone" :label="__('payroll.phone')" :value="$employee->phone" />
                                <x-ui.input name="hire_date" type="date" :label="__('payroll.hire_date')" :value="$employee->hire_date?->toDateString()" />
                                <x-ui.select name="payroll_type" :label="__('payroll.payroll_type')" required>
                                    @foreach (['monthly', 'daily', 'hourly'] as $type)
                                        <option value="{{ $type }}" @selected($employee->payroll_type === $type)>{{ __('payroll.'.$type) }}</option>
                                    @endforeach
                                </x-ui.select>
                                <x-ui.input name="payroll_rate" type="number" step="0.0001" min="0" :label="__('payroll.payroll_rate')" :value="$employee->payroll_rate" required />
                                <x-ui.input name="standard_daily_minutes" type="number" min="60" max="1440" :label="__('payroll.standard_daily_minutes')" :value="$employee->standard_daily_minutes" required />
                                <x-ui.input name="overtime_rate" type="number" step="0.0001" min="0" :label="__('payroll.overtime_rate')" :value="$employee->overtime_rate" />
                                <x-ui.select name="is_active" :label="__('payroll.status')" required>
                                    <option value="1" @selected($employee->is_active)>{{ __('payroll.active') }}</option>
                                    <option value="0" @selected(! $employee->is_active)>{{ __('payroll.inactive') }}</option>
                                </x-ui.select>
                                <div class="sm:col-span-2">
                                    <div class="mb-2 text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('payroll.working_days') }}</div>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ([1,2,3,4,5,6,7] as $day)
                                            <label class="inline-flex items-center gap-2 rounded-md border border-slate-200 px-3 py-2 text-xs dark:border-slate-700">
                                                <input type="checkbox" name="working_days[]" value="{{ $day }}" @checked(in_array($day, $employee->working_days ?: config('payroll.default_working_days', [1,2,3,4,5]), true))>
                                                <span>{{ __('payroll.days.'.$day) }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                                <div class="sm:col-span-2 flex justify-end">
                                    <x-ui.button type="submit">{{ __('common.save') }}</x-ui.button>
                                </div>
                            </form>
                        @endcan
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('payroll.no_employees') }}</p>
                @endforelse
            </div>
        </x-ui.card>
    </div>

    @can('hr.manage')
        <div class="mt-5 grid gap-5 xl:grid-cols-2">
            <x-ui.card>
                <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('payroll.add_leave') }}</h2></x-slot:header>
                <form method="POST" action="{{ route('hr.leaves.store') }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    <x-ui.select name="employee_id" :label="__('payroll.employee')" required>
                        @foreach ($employees->where('is_active', true) as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->employee_code }} — {{ $employee->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input name="leave_type" :label="__('payroll.leave_type')" value="annual" required />
                    <x-ui.select name="is_paid" :label="__('payroll.leave')" required>
                        <option value="1">{{ __('payroll.paid_leave') }}</option>
                        <option value="0">{{ __('payroll.unpaid_leave') }}</option>
                    </x-ui.select>
                    <div></div>
                    <x-ui.input name="start_date" type="date" :label="__('payroll.start_date')" required />
                    <x-ui.input name="end_date" type="date" :label="__('payroll.end_date')" required />
                    <div class="sm:col-span-2"><x-ui.input name="note" :label="__('payroll.note')" /></div>
                    <div class="sm:col-span-2 flex justify-end"><x-ui.button type="submit" icon="plus">{{ __('payroll.add_leave') }}</x-ui.button></div>
                </form>
            </x-ui.card>

            <x-ui.card>
                <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('payroll.add_adjustment') }}</h2></x-slot:header>
                <form method="POST" action="{{ route('hr.adjustments.store') }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    <x-ui.select name="employee_id" :label="__('payroll.employee')" required>
                        @foreach ($employees->where('is_active', true) as $employee)
                            <option value="{{ $employee->id }}">{{ $employee->employee_code }} — {{ $employee->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select name="type" :label="__('payroll.adjustments')" required>
                        <option value="earning">{{ __('payroll.earning') }}</option>
                        <option value="deduction">{{ __('payroll.deduction') }}</option>
                    </x-ui.select>
                    <x-ui.input name="effective_date" type="date" :label="__('payroll.effective_date')" required />
                    <x-ui.input name="amount" type="number" step="0.0001" min="0.0001" :label="__('payroll.amount')" required />
                    <x-ui.input name="label" :label="__('payroll.label')" required />
                    <x-ui.input name="note" :label="__('payroll.note')" />
                    <div class="sm:col-span-2 flex justify-end"><x-ui.button type="submit" icon="plus">{{ __('payroll.add_adjustment') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        </div>
    @endcan

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('payroll.leave') }}</h2></x-slot:header>
            <div class="space-y-2">
                @forelse ($leaves as $leave)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700">
                        <div>
                            <span class="font-medium text-slate-900 dark:text-white">{{ $leave->employee?->name }}</span>
                            <span class="text-slate-500"> · {{ $leave->leave_type }} · {{ $leave->start_date?->toDateString() }} → {{ $leave->end_date?->toDateString() }}</span>
                        </div>
                        <x-ui.badge :tone="$leave->is_paid ? 'success' : 'neutral'">{{ $leave->is_paid ? __('payroll.paid_leave') : __('payroll.unpaid_leave') }}</x-ui.badge>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('payroll.no_leaves') }}</p>
                @endforelse
            </div>
        </x-ui.card>

        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('payroll.adjustments') }}</h2></x-slot:header>
            <div class="space-y-2">
                @forelse ($adjustments as $adjustment)
                    <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700">
                        <div>
                            <span class="font-medium text-slate-900 dark:text-white">{{ $adjustment->employee?->name }}</span>
                            <span class="text-slate-500"> · {{ $adjustment->label }} · {{ $adjustment->effective_date?->toDateString() }}</span>
                        </div>
                        <div class="font-semibold {{ $adjustment->type === 'earning' ? 'text-emerald-600' : 'text-red-600' }}">
                            {{ $adjustment->type === 'earning' ? '+' : '-' }}{{ $adjustment->amount }}
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('payroll.no_adjustments') }}</p>
                @endforelse
            </div>
        </x-ui.card>
    </div>

    <div class="mt-5">
        <x-ui.card>
            <x-slot:header>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('payroll.payroll_runs') }}</h2>
                    @can('payroll.manage')
                        <form method="POST" action="{{ route('hr.payroll.generate') }}" class="flex flex-wrap items-end gap-2">
                            @csrf
                            <x-ui.input name="period_start" type="date" :label="__('payroll.from')" :value="$from" required />
                            <x-ui.input name="period_end" type="date" :label="__('payroll.to')" :value="$to" required />
                            <x-ui.button type="submit" icon="plus">{{ __('payroll.generate_run') }}</x-ui.button>
                        </form>
                    @endcan
                </div>
            </x-slot:header>

            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('payroll.run_number') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.period') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.run_status') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.employees_count') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.gross') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.deductions') }}</x-ui.th>
                            <x-ui.th>{{ __('payroll.net') }}</x-ui.th>
                            <x-ui.th></x-ui.th>
                        </tr>
                    </x-slot:head>
                    @forelse ($runs as $run)
                        <tr>
                            <x-ui.td>{{ $run->number }}</x-ui.td>
                            <x-ui.td>{{ $run->period_start?->toDateString() }} → {{ $run->period_end?->toDateString() }}</x-ui.td>
                            <x-ui.td><x-ui.badge :tone="$run->status === 'finalized' ? 'success' : 'warning'">{{ __('payroll.'.$run->status) }}</x-ui.badge></x-ui.td>
                            <x-ui.td>{{ $run->lines_count }}</x-ui.td>
                            <x-ui.td>{{ $run->total_gross }}</x-ui.td>
                            <x-ui.td>{{ $run->total_deductions }}</x-ui.td>
                            <x-ui.td class="font-semibold">{{ $run->total_net }}</x-ui.td>
                            <x-ui.td><x-ui.button href="{{ route('hr.payroll.show', $run) }}" size="sm" variant="secondary">{{ __('payroll.open_run') }}</x-ui.button></x-ui.td>
                        </tr>
                    @empty
                        <tr><x-ui.td colspan="8">{{ __('payroll.no_runs') }}</x-ui.td></tr>
                    @endforelse
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>
</x-app.page>
@endsection
