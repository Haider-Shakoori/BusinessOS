@extends('layouts.app')

@section('content')
    <x-app.page icon="chart-bar"
        :title="__('expenses.report')"
        :subtitle="__('expenses.report_subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('expenses.title'), 'url' => route('expenses.index')],
            ['label' => __('expenses.report')],
        ]"
    >
        <x-slot:actions>
            <x-ui.button href="{{ route('expenses.report.export', request()->only('category_id', 'date_from', 'date_to')) }}" variant="secondary">
                {{ __('exports.export_csv') }}
            </x-ui.button>
            <x-ui.button variant="secondary" href="{{ route('expenses.index') }}" icon="chevron-right">
                {{ __('expenses.title') }}
            </x-ui.button>
            @can('expenses.manage')
                <x-ui.button href="{{ route('expenses.create') }}" icon="plus">{{ __('expenses.add') }}</x-ui.button>
            @endcan
        </x-slot:actions>

        <x-ui.card>
            <form method="GET" action="{{ route('expenses.report') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <x-ui.select
                    name="category_id"
                    :label="__('expenses.category_filter')"
                    :placeholder="__('expenses.all_categories')"
                    :value="$categoryId"
                >
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) $categoryId === (string) $category->id)>
                            {{ $category->name }}
                        </option>
                    @endforeach
                </x-ui.select>

                <div>
                    <x-ui.input
                        name="date_from"
                        type="date"
                        :label="__('expenses.date_from')"
                        :value="old('date_from', $dateFrom)"
                    />
                </div>

                <div>
                    <x-ui.input
                        name="date_to"
                        type="date"
                        :label="__('expenses.date_to')"
                        :value="old('date_to', $dateTo)"
                    />
                </div>

                <div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4">
                    <x-ui.button type="submit" icon="chart-bar">{{ __('expenses.report_generate') }}</x-ui.button>
                    @if ($categoryId !== null || $dateFrom !== null || $dateTo !== null)
                        <x-ui.button variant="secondary" href="{{ route('expenses.report') }}">
                            {{ __('actions.clear') }}
                        </x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>

        <div class="grid gap-6 lg:grid-cols-3">
            <div>
                <x-ui.stat-card
                    :title="__('expenses.report_total')"
                    :value="$total"
                    icon="arrow-trending-down"
                    tone="danger"
                    :hint="trans_choice('expenses.report_count', $expenses->count()) . ' · ' . $baseCurrency"
                />
            </div>
        </div>

        @if ($expenses->isEmpty())
            <x-ui.card>
                <x-ui.empty-state
                    :title="__('expenses.report_empty')"
                    :description="__('expenses.report_empty_description')"
                    icon="arrow-trending-down"
                />
            </x-ui.card>
        @else
            <x-ui.table :caption="__('expenses.report')">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('expenses.columns.number') }}</x-ui.th>
                        <x-ui.th>{{ __('expenses.columns.date') }}</x-ui.th>
                        <x-ui.th>{{ __('expenses.columns.category') }}</x-ui.th>
                        <x-ui.th>{{ __('expenses.columns.vendor') }}</x-ui.th>
                        <x-ui.th class="text-end">{{ __('expenses.columns.amount') }}</x-ui.th>
                        <x-ui.th class="text-center">{{ __('expenses.columns.receipt') }}</x-ui.th>
                    </tr>
                </x-slot:head>

                @foreach ($expenses as $expense)
                    <tr>
                        <x-ui.td>
                            <a
                                href="{{ route('expenses.show', $expense) }}"
                                class="whitespace-nowrap font-semibold text-slate-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                            >
                                {{ $expense->expense_number }}
                            </a>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $expense->expense_date->format('Y-m-d') }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-slate-500 dark:text-slate-400">{{ $expense->category?->name ?: __('expenses.no_category') }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-slate-500 dark:text-slate-400">{{ $expense->vendor ?: '—' }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap font-medium tabular-nums text-slate-900 dark:text-white" dir="ltr">
                                {{ $expense->amount }} <span class="text-xs font-medium text-slate-500 dark:text-slate-400">{{ $expense->currency_code }}</span>
                            </span>
                        </x-ui.td>
                        <x-ui.td class="text-center">
                            @if ($expense->receipt_path)
                                <x-ui.icon name="document-text" class="mx-auto size-5 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                            @else
                                <span class="text-slate-300 dark:text-slate-600">—</span>
                            @endif
                        </x-ui.td>
                    </tr>
                @endforeach
            </x-ui.table>
        @endif
    </x-app.page>
@endsection
