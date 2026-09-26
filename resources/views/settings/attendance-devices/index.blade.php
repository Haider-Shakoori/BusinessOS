@extends('layouts.app')

@section('content')
    <x-app.page icon="clock" :title="__('attendance.title')" :subtitle="__('attendance.subtitle')">
        @if (session('status'))
            <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
        @endif

        @if (session('attendance_error'))
            <div class="mb-5"><x-ui.alert type="danger">{{ session('attendance_error') }}</x-ui.alert></div>
        @endif

        <div class="space-y-5">
            <x-ui.card>
                <x-slot:header>
                    <div>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('attendance.technical_requirements') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('attendance.technical_requirements_helper') }}</p>
                    </div>
                </x-slot:header>

                <p class="text-sm text-slate-600 dark:text-slate-300">{{ __('attendance.connection_notes') }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($brands as $brand)
                        <x-ui.badge tone="neutral">{{ $brand['label'] }}</x-ui.badge>
                    @endforeach
                </div>
            </x-ui.card>

            @can('settings.manage')
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('attendance.add_device') }}</h2>
                    </x-slot:header>

                    <form method="POST" action="{{ route('settings.attendance-devices.store') }}" class="space-y-5">
                        @csrf
                        @include('settings.attendance-devices._form')
                        <div class="flex justify-end">
                            <x-ui.button type="submit" icon="plus">{{ __('attendance.add_device') }}</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endcan

            <x-ui.card>
                <x-slot:header>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('attendance.existing_devices') }}</h2>
                </x-slot:header>

                @if ($devices->isEmpty())
                    <x-ui.empty-state icon="clock" :title="__('attendance.no_devices')" />
                @else
                    <div class="space-y-3">
                        @foreach ($devices as $device)
                            <div class="rounded-[9px] border border-slate-200 bg-slate-50/70 p-4 dark:border-slate-700 dark:bg-slate-800/50">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="font-semibold text-slate-900 dark:text-white">{{ $device->name }}</h3>
                                            <x-ui.badge :tone="$device->enabled ? 'success' : 'neutral'">
                                                {{ $brands[$device->brand]['label'] ?? $device->brand }}
                                            </x-ui.badge>
                                            @if ($device->last_status)
                                                <x-ui.badge :tone="$device->last_status === 'online' ? 'success' : ($device->last_status === 'error' || $device->last_status === 'offline' ? 'danger' : 'neutral')">
                                                    {{ $device->last_status }}
                                                </x-ui.badge>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                                            {{ $connections[$device->connection_type]['label'] ?? $device->connection_type }}
                                            @if ($device->model) · {{ $device->model }} @endif
                                            @if ($device->serial_number) · {{ $device->serial_number }} @endif
                                        </p>
                                        <div class="mt-2 grid gap-1 text-xs text-slate-500 sm:grid-cols-2 dark:text-slate-400">
                                            <span>{{ __('attendance.last_seen') }}: {{ $device->last_seen_at?->diffForHumans() ?? __('attendance.never') }}</span>
                                            <span>{{ __('attendance.last_sync') }}: {{ $device->last_sync_at?->diffForHumans() ?? __('attendance.never') }}</span>
                                        </div>
                                    </div>

                                    @can('settings.manage')
                                        <div class="flex flex-wrap gap-2">
                                            <form method="POST" action="{{ route('settings.attendance-devices.test', $device) }}">
                                                @csrf
                                                <x-ui.button type="submit" variant="secondary" size="sm" icon="bolt">{{ __('attendance.test_connection') }}</x-ui.button>
                                            </form>
                                            <form method="POST" action="{{ route('settings.attendance-devices.destroy', $device) }}" onsubmit="return confirm('{{ __('attendance.delete_device') }}?')">
                                                @csrf
                                                @method('DELETE')
                                                <x-ui.button type="submit" variant="danger" size="sm" icon="trash">{{ __('attendance.delete_device') }}</x-ui.button>
                                            </form>
                                        </div>
                                    @endcan
                                </div>

                                @if (in_array($connections[$device->connection_type]['transport'] ?? null, ['push'], true))
                                    <div class="mt-4 grid gap-3 rounded-[7px] border border-brand-100 bg-brand-50/70 p-3 sm:grid-cols-2 dark:border-brand-500/20 dark:bg-brand-500/10">
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700 dark:text-brand-300">{{ __('attendance.push_endpoint') }}</p>
                                            <code class="mt-1 block break-all text-xs text-slate-700 dark:text-slate-200">{{ route('attendance.push', $device) }}</code>
                                        </div>
                                        <div>
                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-700 dark:text-brand-300">{{ __('attendance.push_token') }}</p>
                                            <code class="mt-1 block break-all text-xs text-slate-700 dark:text-slate-200">{{ $device->push_token }}</code>
                                        </div>
                                        <p class="sm:col-span-2 text-xs text-slate-500 dark:text-slate-400">{{ __('attendance.push_helper') }}</p>
                                    </div>
                                @endif

                                @can('settings.manage')
                                    <details class="mt-4 border-t border-slate-200 pt-4 dark:border-slate-700">
                                        <summary class="cursor-pointer text-sm font-semibold text-brand-700 dark:text-brand-300">{{ __('attendance.edit_device') }}</summary>
                                        <form method="POST" action="{{ route('settings.attendance-devices.update', $device) }}" class="mt-4 space-y-5">
                                            @csrf
                                            @method('PATCH')
                                            @include('settings.attendance-devices._form', ['device' => $device])
                                            <div class="flex justify-end">
                                                <x-ui.button type="submit" icon="check-circle">{{ __('attendance.save_device') }}</x-ui.button>
                                            </div>
                                        </form>
                                    </details>
                                @endcan
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>

            <x-ui.card>
                <x-slot:header>
                    <div>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('attendance.employees') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('attendance.employees_helper') }}</p>
                    </div>
                </x-slot:header>

                @can('settings.manage')
                    <form method="POST" action="{{ route('settings.attendance-employees.store') }}" class="grid gap-4 md:grid-cols-5">
                        @csrf
                        <x-ui.input name="employee_code" :label="__('attendance.employee_code')" required maxlength="80" />
                        <x-ui.input name="name" :label="__('attendance.employee_name')" required maxlength="255" />
                        <x-ui.select name="payroll_type" :label="__('attendance.payroll_type')" required>
                            <option value="monthly">{{ __('attendance.monthly') }}</option>
                            <option value="daily">{{ __('attendance.daily') }}</option>
                            <option value="hourly">{{ __('attendance.hourly') }}</option>
                        </x-ui.select>
                        <x-ui.input name="payroll_rate" type="number" step="0.0001" min="0" :label="__('attendance.payroll_rate')" />
                        <div class="flex items-end">
                            <x-ui.button type="submit" icon="plus">{{ __('attendance.add_employee') }}</x-ui.button>
                        </div>
                    </form>
                @endcan

                @if ($employees->isNotEmpty() && $devices->isNotEmpty())
                    @can('settings.manage')
                        <form method="POST" action="{{ route('settings.attendance-devices.map-employee', $devices->first()) }}" class="mt-5 grid gap-4 border-t border-slate-100 pt-5 md:grid-cols-4 dark:border-slate-700" x-data="{ device: '{{ $devices->first()->uuid }}' }" x-bind:action="'{{ url('/settings/attendance-devices') }}/' + device + '/map-employee'">
                            @csrf
                            <x-ui.select name="device_uuid_proxy" :label="__('attendance.choose_device')" x-model="device">
                                @foreach ($devices as $device)
                                    <option value="{{ $device->uuid }}">{{ $device->name }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.select name="employee_id" :label="__('attendance.choose_employee')" required>
                                @foreach ($employees as $employee)
                                    <option value="{{ $employee->id }}">{{ $employee->employee_code }} — {{ $employee->name }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.input name="device_user_id" :label="__('attendance.device_user_id')" required maxlength="100" />
                            <div class="flex items-end">
                                <x-ui.button type="submit" icon="check-circle">{{ __('attendance.map_employee') }}</x-ui.button>
                            </div>
                        </form>
                    @endcan
                @endif

                <div class="mt-5">
                    @if ($mappings->isEmpty())
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('attendance.no_mappings') }}</p>
                    @else
                        <div class="overflow-x-auto">
                            <x-ui.table :caption="__('attendance.mappings')">
                                <x-slot:head>
                                    <tr>
                                        <x-ui.th>{{ __('attendance.fields.name') }}</x-ui.th>
                                        <x-ui.th>{{ __('attendance.employee_code') }}</x-ui.th>
                                        <x-ui.th>{{ __('attendance.employee_name') }}</x-ui.th>
                                        <x-ui.th>{{ __('attendance.device_user_id') }}</x-ui.th>
                                    </tr>
                                </x-slot:head>
                                @foreach ($mappings as $mapping)
                                    <tr>
                                        <x-ui.td>{{ $mapping->device?->name }}</x-ui.td>
                                        <x-ui.td>{{ $mapping->employee?->employee_code }}</x-ui.td>
                                        <x-ui.td>{{ $mapping->employee?->name }}</x-ui.td>
                                        <x-ui.td><span dir="ltr">{{ $mapping->device_user_id }}</span></x-ui.td>
                                    </tr>
                                @endforeach
                            </x-ui.table>
                        </div>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card>
                <x-slot:header>
                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('attendance.recent_logs') }}</h2>
                </x-slot:header>

                @if ($recentLogs->isEmpty())
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('attendance.no_logs') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <x-ui.table :caption="__('attendance.recent_logs')">
                            <x-slot:head>
                                <tr>
                                    <x-ui.th>{{ __('attendance.fields.name') }}</x-ui.th>
                                    <x-ui.th>{{ __('attendance.employee_name') }}</x-ui.th>
                                    <x-ui.th>{{ __('attendance.device_user_id') }}</x-ui.th>
                                    <x-ui.th>{{ __('attendance.fields.connection_type') }}</x-ui.th>
                                    <x-ui.th>{{ __('attendance.last_seen') }}</x-ui.th>
                                </tr>
                            </x-slot:head>
                            @foreach ($recentLogs as $log)
                                <tr>
                                    <x-ui.td>{{ $log->device?->name }}</x-ui.td>
                                    <x-ui.td>{{ $log->employee?->name ?? __('attendance.unmapped') }}</x-ui.td>
                                    <x-ui.td><span dir="ltr">{{ $log->device_user_id }}</span></x-ui.td>
                                    <x-ui.td>{{ $log->verification_type }}</x-ui.td>
                                    <x-ui.td>{{ $log->occurred_at?->timezone($log->device?->timezone ?? config('app.timezone'))->format('Y-m-d H:i:s') }}</x-ui.td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </div>
                @endif
            </x-ui.card>

            <div>
                <x-ui.button href="{{ route('settings.index') }}" variant="secondary" icon="arrow-uturn-left">{{ __('navigation.settings') }}</x-ui.button>
            </div>
        </div>
    </x-app.page>
@endsection
