@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('imports.preview_title')"
        :subtitle="__('imports.preview_subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('modules.' . $type), 'url' => route($routes['index'])],
            ['label' => __('imports.preview_title')],
        ]"
    >
        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.stat-card
                :title="__('imports.total_rows')"
                :value="$preview->totalRows"
                icon="chart-bar"
                tone="neutral"
            />
            <x-ui.stat-card
                :title="__('imports.valid_rows')"
                :value="$preview->validRows"
                icon="check-circle"
                tone="{{ $preview->importable() ? 'success' : 'info' }}"
            />
            <x-ui.stat-card
                :title="__('imports.invalid_rows')"
                :value="$preview->invalidRows"
                icon="x-circle"
                tone="{{ $preview->invalidRows === 0 ? 'neutral' : 'danger' }}"
            />
        </div>

        @if (session('error'))
            <div class="mt-6">
                <x-ui.alert type="danger" :title="session('error')">
                    {{ __('imports.execution_guard') }}
                </x-ui.alert>
            </div>
        @endif

        @if (! $preview->headerValid)
            <div class="mt-6">
                <x-ui.alert type="danger" :title="__('imports.header_invalid')">
                    <ul class="mt-1 list-inside list-disc space-y-1">
                        @foreach ($preview->headerErrors as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </x-ui.alert>
            </div>

            <div class="mt-6">
                <x-ui.card>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm text-slate-600 dark:text-slate-300">{{ __('imports.header_invalid_description') }}</p>
                        <x-ui.button :href="route($routes['import'])" variant="secondary" icon="arrow-left-on-rectangle">
                            {{ __('imports.back_to_upload') }}
                        </x-ui.button>
                    </div>
                </x-ui.card>
            </div>
        @else
            @if ($preview->invalidRows > 0)
                <div class="mt-6">
                    <x-ui.alert type="danger" :title="__('imports.rows_invalid', ['count' => $preview->invalidRows, 'total' => $preview->totalRows])">
                        {{ __('imports.rows_invalid_description') }}
                    </x-ui.alert>
                </div>

                <div class="mt-6">
                    <x-ui.card>
                        <x-slot:header>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('imports.errors_title') }}</h2>
                        </x-slot:header>
                        <ul class="space-y-2 text-sm text-slate-700 dark:text-slate-200">
                            @php
                                $displayErrors = array_slice($preview->rowErrors, 0, 50);
                                $hidden = count($preview->rowErrors) - count($displayErrors);
                            @endphp
                            @forelse ($displayErrors as $error)
                                <li class="flex items-start gap-2">
                                    <x-ui.icon name="exclamation-triangle" class="mt-0.5 size-4 shrink-0 text-red-500 dark:text-red-400" />
                                    <span>{{ $error }}</span>
                                </li>
                            @empty
                                <li class="text-slate-500 dark:text-slate-400">{{ __('imports.no_errors') }}</li>
                            @endforelse
                        </ul>
                        @if ($hidden > 0)
                            <p class="mt-4 text-sm font-medium text-slate-500 dark:text-slate-400">
                                {{ __('imports.more_errors', ['count' => $hidden]) }}
                            </p>
                        @endif
                        <div class="mt-5 flex flex-wrap items-center gap-3">
                            <x-ui.button :href="route($routes['import'])" variant="secondary" icon="arrow-left-on-rectangle">
                                {{ __('imports.back_to_upload') }}
                            </x-ui.button>
                        </div>
                    </x-ui.card>
                </div>
            @else
                <div class="mt-6">
                    <x-ui.alert type="success" :title="__('imports.preview_ready', ['count' => $preview->totalRows])">
                        {{ __('imports.preview_ready_description') }}
                    </x-ui.alert>
                </div>
            @endif

            @if (count($preview->rows))
                <div class="mt-6">
                    <x-ui.card>
                        <x-slot:header>
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('imports.preview_rows', ['count' => min($previewRows, count($preview->rows)), 'total' => $preview->totalRows]) }}</h2>
                        </x-slot:header>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
                                <thead class="bg-slate-50 dark:bg-slate-800/60">
                                    <tr>
                                        <th scope="col" class="px-4 py-2.5 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                            {{ __('imports.row_label') }}
                                        </th>
                                        @foreach ($preview->header as $key)
                                            <th scope="col" class="px-4 py-2.5 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                                                {{ $columnLabels[$key] ?? $key }}
                                            </th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-700 dark:bg-slate-800">
                                    @foreach (array_slice($preview->rows, 0, $previewRows) as $row)
                                        <tr>
                                            <td class="whitespace-nowrap px-4 py-2.5 text-sm text-slate-500 dark:text-slate-400">{{ $row->number }}</td>
                                            @foreach ($preview->header as $key)
                                                <td class="px-4 py-2.5 text-sm text-slate-900 dark:text-slate-100">
                                                    {{ isset($row->values[$key]) ? $row->values[$key] : '' }}
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </x-ui.card>
                </div>
            @endif

            @if ($preview->headerValid && $preview->invalidRows === 0)
                <div class="mt-6">
                    <x-ui.card>
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <p class="text-sm text-slate-600 dark:text-slate-400">{{ __('imports.confirm_note', ['count' => $preview->totalRows]) }}</p>

                            <div class="flex flex-wrap items-center gap-3">
                                <form method="POST" action="{{ route($routes['cancel'], ['token' => $token]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" variant="secondary" icon="x-mark">
                                        {{ __('imports.cancel') }}
                                    </x-ui.button>
                                </form>

                                <form method="POST" action="{{ route($routes['execute'], ['token' => $token]) }}">
                                    @csrf
                                    <x-ui.button type="submit" icon="check">
                                        {{ __('imports.confirm') }}
                                    </x-ui.button>
                                </form>
                            </div>
                        </div>
                    </x-ui.card>
                </div>
            @endif
        @endif
    </x-app.page>
@endsection