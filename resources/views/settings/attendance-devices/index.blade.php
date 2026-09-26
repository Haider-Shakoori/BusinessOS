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
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('attendance.bridge.title') }}</h2>
                                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('attendance.bridge.helper') }}</p>
                            </div>
                            <x-ui.button href="/downloads/BusinessOS-Attendance-Bridge.zip" variant="secondary" size="sm" icon="arrow-down-tray">
                                {{ __('attendance.bridge.download') }}
                            </x-ui.button>
                        </div>
                    </x-slot:header>

                    <form method="POST" action="{{ route('settings.attendance-bridges.store') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                        @csrf
                        <div class="min-w-0 flex-1">
                            <x-ui.input name="name" :label="__('attendance.bridge.name')" :placeholder="__('attendance.bridge.name_placeholder')" required maxlength="255" />
                        </div>
                        <x-ui.button type="submit" icon="plus">{{ __('attendance.bridge.create') }}</x-ui.button>
                    </form>

                    @if ($bridges->isNotEmpty())
                        <div class="mt-5 space-y-3 border-t border-slate-100 pt-5 dark:border-slate-700">
                            @foreach ($bridges as $bridge)
                                <div class="rounded-[9px] border border-slate-200 bg-slate-50/70 p-4 dark:border-slate-700 dark:bg-slate-800/50">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h3 class="font-semibold text-slate-900 dark:text-white">{{ $bridge->name }}</h3>
                                                <x-ui.badge :tone="$bridge->isOnline() ? 'success' : 'neutral'">
                                                    {{ $bridge->isOnline() ? __('attendance.bridge.online') : __('attendance.bridge.offline') }}
                                                </x-ui.badge>
                                            </div>
                                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                                {{ __('attendance.bridge.last_seen') }}:
                                                {{ $bridge->last_seen_at?->diffForHumans() ?? __('attendance.never') }}
                                                @if ($bridge->hostname) · {{ $bridge->hostname }} @endif
                                                @if ($bridge->version) · v{{ $bridge->version }} @endif
                                            </p>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <form method="POST" action="{{ route('settings.attendance-bridges.regenerate-token', $bridge) }}" onsubmit="return confirm('{{ __('attendance.bridge.regenerate_confirm') }}')">
                                                @csrf
                                                <x-ui.button type="submit" variant="secondary" size="sm">{{ __('attendance.bridge.regenerate') }}</x-ui.button>
                                            </form>
                                            <form method="POST" action="{{ route('settings.attendance-bridges.destroy', $bridge) }}" onsubmit="return confirm('{{ __('attendance.bridge.delete_confirm') }}')">
                                                @csrf
                                                @method('DELETE')
                                                <x-ui.button type="submit" variant="danger" size="sm" icon="trash">{{ __('attendance.bridge.delete') }}</x-ui.button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="mt-4 grid gap-3 md:grid-cols-2">
                                        <div>
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('attendance.bridge.uuid') }}</p>
                                            <code class="mt-1 block break-all rounded bg-white p-2 text-xs text-slate-700 dark:bg-slate-900 dark:text-slate-200">{{ $bridge->uuid }}</code>
                                        </div>
                                        <div>
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('attendance.bridge.token') }}</p>
                                            <code class="mt-1 block break-all rounded bg-white p-2 text-xs text-slate-700 dark:bg-slate-900 dark:text-slate-200">{{ $bridge->token }}</code>
                                        </div>
                                    </div>
                                    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">{{ __('attendance.bridge.install_hint') }}</p>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-ui.card>

                <x-ui.card>
                    <div
                        x-data="{
                            loading: false,
                            result: null,
                            error: null,
                            statusMessage: null,
                            sleep(ms) { return new Promise(resolve => setTimeout(resolve, ms)); },
                            applyResult(result) {
                                this.result = result;
                                const set = (id, value) => {
                                    if (value === null || value === undefined || value === '') return;
                                    const element = document.getElementById(id);
                                    if (!element) return;
                                    element.value = value;
                                    element.dispatchEvent(new Event('input', { bubbles: true }));
                                    element.dispatchEvent(new Event('change', { bubbles: true }));
                                };
                                set('host', result.ip);
                                set('brand', result.brand);
                                set('connection_type', result.connection_type);
                                set('port', result.port);
                                set('base_url', result.base_url);
                                set('attendance_bridge_id', result.bridge_id);
                                const name = document.getElementById('name');
                                if (name && !name.value.trim() && result.brand_label) {
                                    name.value = result.brand_label + ' - ' + result.ip;
                                    name.dispatchEvent(new Event('input', { bubbles: true }));
                                }
                                document.getElementById('name')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            },
                            async pollJob(jobId) {
                                for (let attempt = 0; attempt < 60; attempt++) {
                                    await this.sleep(1500);
                                    const response = await fetch('{{ url('/settings/attendance-device-discovery') }}/' + jobId, {
                                        headers: { 'Accept': 'application/json' },
                                    });
                                    const payload = await response.json();
                                    if (payload.status === 'completed' && payload.result) {
                                        this.applyResult(payload.result);
                                        return;
                                    }
                                    if (['failed', 'expired'].includes(payload.status)) {
                                        throw new Error(payload.message || '{{ __('attendance.discovery.failed') }}');
                                    }
                                }
                                throw new Error('{{ __('attendance.bridge.discovery_timeout') }}');
                            },
                            async detect() {
                                const ip = this.$refs.ip.value.trim();
                                if (!ip || this.loading) return;
                                this.loading = true;
                                this.error = null;
                                this.result = null;
                                this.statusMessage = null;
                                try {
                                    const bridgeSelect = document.getElementById('attendance_bridge_id');
                                    const response = await fetch('{{ route('settings.attendance-devices.detect') }}', {
                                        method: 'POST',
                                        headers: {
                                            'Accept': 'application/json',
                                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                            'Content-Type': 'application/json',
                                        },
                                        body: JSON.stringify({
                                            ip,
                                            bridge_id: bridgeSelect?.value || null,
                                        }),
                                    });
                                    const payload = await response.json();
                                    if (!response.ok || !payload.ok) {
                                        throw new Error(payload.message || '{{ __('attendance.discovery.failed') }}');
                                    }
                                    if (payload.pending) {
                                        this.statusMessage = payload.message;
                                        await this.pollJob(payload.job_id);
                                    } else {
                                        this.applyResult(payload.result);
                                    }
                                } catch (e) {
                                    this.error = e.message || '{{ __('attendance.discovery.failed') }}';
                                } finally {
                                    this.loading = false;
                                    this.statusMessage = null;
                                }
                            }
                        }"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('attendance.discovery.title') }}</h2>
                                <p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">{{ __('attendance.discovery.helper_bridge') }}</p>
                            </div>
                            <x-ui.badge tone="brand">{{ __('attendance.discovery.best_effort') }}</x-ui.badge>
                        </div>

                        <div class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-end">
                            <div class="min-w-0 flex-1">
                                <x-ui.input
                                    x-ref="ip"
                                    name="attendance_detection_ip"
                                    :label="__('attendance.discovery.ip_label')"
                                    placeholder="192.168.1.201"
                                    dir="ltr"
                                    autocomplete="off"
                                    x-on:change="detect()"
                                />
                            </div>
                            <x-ui.button type="button" icon="bolt" x-on:click="detect()" x-bind:disabled="loading">
                                <span x-show="!loading">{{ __('attendance.discovery.detect') }}</span>
                                <span x-show="loading" x-cloak>{{ __('attendance.discovery.detecting') }}</span>
                            </x-ui.button>
                        </div>

                        <p x-show="statusMessage" x-cloak class="mt-3 text-sm text-brand-700 dark:text-brand-300" x-text="statusMessage"></p>

                        <div x-show="error" x-cloak class="mt-4">
                            <x-ui.alert type="danger"><span x-text="error"></span></x-ui.alert>
                        </div>

                        <div x-show="result" x-cloak class="mt-4 rounded-[9px] border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800/50">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-slate-900 dark:text-white" x-text="result?.brand_label || '{{ __('attendance.discovery.unknown_device') }}'"></span>
                                <span class="rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300" x-text="result?.confidence_label"></span>
                                <span class="text-xs text-slate-500 dark:text-slate-400" x-text="result?.confidence ? result.confidence + '%' : ''"></span>
                                <span x-show="result?.bridge_name" class="text-xs text-slate-500 dark:text-slate-400" x-text="result?.bridge_name ? '· ' + result.bridge_name : ''"></span>
                            </div>
                            <div class="mt-3 grid gap-2 text-xs text-slate-600 sm:grid-cols-2 lg:grid-cols-4 dark:text-slate-300">
                                <div><span class="font-semibold">{{ __('attendance.discovery.connection') }}:</span> <span x-text="result?.connection_label || '—'"></span></div>
                                <div><span class="font-semibold">{{ __('attendance.fields.port') }}:</span> <span x-text="result?.port || '—'"></span></div>
                                <div><span class="font-semibold">{{ __('attendance.discovery.open_ports') }}:</span> <span x-text="result?.open_ports?.length ? result.open_ports.join(', ') : '—'"></span></div>
                                <div><span class="font-semibold">{{ __('attendance.discovery.reachable') }}:</span> <span x-text="result?.reachable ? '{{ __('attendance.discovery.yes') }}' : '{{ __('attendance.discovery.no') }}'"></span></div>
                            </div>
                            <p class="mt-3 text-xs text-amber-700 dark:text-amber-300" x-text="result?.warning"></p>
                            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ __('attendance.discovery.autofill_notice') }}</p>
                        </div>
                    </div>
                </x-ui.card>
            @endcan

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
                                            @can('settings.manage')
                                                <code class="mt-1 block break-all text-xs text-slate-700 dark:text-slate-200">{{ $device->push_token }}</code>
                                            @else
                                                <span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">••••••••••••••••</span>
                                            @endcan
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
                    <div>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('attendance.payroll_preview') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                            {{ __('attendance.payroll_preview_helper', ['from' => $payrollPeriodStart->format('Y-m-d'), 'to' => $payrollPeriodEnd->format('Y-m-d')]) }}
                        </p>
                    </div>
                </x-slot:header>

                @if ($payrollSummaries->isEmpty())
                    <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('attendance.no_logs') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <x-ui.table :caption="__('attendance.payroll_preview')">
                            <x-slot:head>
                                <tr>
                                    <x-ui.th>{{ __('attendance.employee_code') }}</x-ui.th>
                                    <x-ui.th>{{ __('attendance.employee_name') }}</x-ui.th>
                                    <x-ui.th>{{ __('attendance.attended_days') }}</x-ui.th>
                                    <x-ui.th>{{ __('attendance.punch_count') }}</x-ui.th>
                                    <x-ui.th>{{ __('attendance.worked_minutes') }}</x-ui.th>
                                    <x-ui.th>{{ __('attendance.missing_checkout_days') }}</x-ui.th>
                                </tr>
                            </x-slot:head>
                            @foreach ($payrollSummaries as $summary)
                                @php $payrollEmployee = $employees->firstWhere('id', $summary['employee_id']); @endphp
                                <tr>
                                    <x-ui.td>{{ $summary['employee_code'] }}</x-ui.td>
                                    <x-ui.td>{{ $payrollEmployee?->name }}</x-ui.td>
                                    <x-ui.td>{{ $summary['attended_days'] }}</x-ui.td>
                                    <x-ui.td>{{ $summary['punch_count'] }}</x-ui.td>
                                    <x-ui.td>{{ $summary['worked_minutes'] }}</x-ui.td>
                                    <x-ui.td>{{ $summary['missing_checkout_days'] }}</x-ui.td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </div>
                @endif
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
