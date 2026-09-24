@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('expenses.title')"
        :subtitle="__('expenses.subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('expenses.title')],
        ]"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('expenses.report') }}" icon="chart-bar">
                {{ __('expenses.report') }}
            </x-ui.button>
            @can('expenses.manage')
                <x-ui.button href="{{ route('expenses.create') }}" icon="plus">{{ __('expenses.add') }}</x-ui.button>
            @endcan
        </x-slot:actions>

        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        @cannot('expenses.manage')
            <div class="mb-6">
                <x-ui.alert type="info">{{ __('expenses.view_only') }}</x-ui.alert>
            </div>
        @endcannot

        <x-ui.card>
            <form method="GET" action="{{ route('expenses.index') }}" role="search" class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label for="expense-search" class="sr-only">{{ __('expenses.search') }}</label>
                    <div class="relative">
                        <x-ui.icon
                            name="search"
                            class="pointer-events-none absolute inset-y-0 start-0 my-auto ms-3 size-4 text-gray-400 dark:text-gray-500"
                            aria-hidden="true"
                        />
                        <input
                            id="expense-search"
                            type="search"
                            name="search"
                            value="{{ $searchTerm }}"
                            placeholder="{{ __('expenses.search_placeholder') }}"
                            class="block w-full rounded-lg border border-gray-300 bg-white py-2 pe-3 ps-10 text-sm text-gray-900 shadow-sm
                                placeholder:text-gray-400 transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30
                                dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-brand-400"
                        />
                    </div>
                </div>

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

                <div class="flex flex-wrap items-end gap-2 md:col-span-2 lg:col-span-4">
                    <x-ui.button type="submit" icon="search">{{ __('actions.search') }}</x-ui.button>
                    @if ($searchTerm !== '' || $categoryId !== null || $dateFrom !== null || $dateTo !== null)
                        <x-ui.button variant="secondary" href="{{ route('expenses.index') }}">
                            {{ __('actions.clear') }}
                        </x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>

        @if ($expenses->isEmpty())
            <x-ui.card>
                <x-ui.empty-state
                    :title="$searchTerm !== '' || $categoryId !== null || $dateFrom !== null || $dateTo !== null ? __('expenses.no_results') : __('expenses.no_expenses')"
                    :description="$searchTerm !== '' || $categoryId !== null || $dateFrom !== null || $dateTo !== null ? __('expenses.no_results_description') : __('expenses.no_expenses_description')"
                    icon="arrow-trending-down"
                >
                    @can('expenses.manage')
                        <x-slot:actions>
                            <x-ui.button href="{{ route('expenses.create') }}" icon="plus">{{ __('expenses.add') }}</x-ui.button>
                        </x-slot:actions>
                    @endcan
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <x-ui.table :caption="__('expenses.table_caption')">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('expenses.columns.number') }}</x-ui.th>
                        <x-ui.th>{{ __('expenses.columns.date') }}</x-ui.th>
                        <x-ui.th>{{ __('expenses.columns.category') }}</x-ui.th>
                        <x-ui.th>{{ __('expenses.columns.vendor') }}</x-ui.th>
                        <x-ui.th class="text-end">{{ __('expenses.columns.amount') }}</x-ui.th>
                        <x-ui.th class="text-center">{{ __('expenses.columns.receipt') }}</x-ui.th>
                        <x-ui.th class="text-end">
                            <span class="sr-only">{{ __('expenses.columns.actions') }}</span>
                        </x-ui.th>
                    </tr>
                </x-slot:head>

                @foreach ($expenses as $expense)
                    <tr>
                        <x-ui.td>
                            <a
                                href="{{ route('expenses.show', $expense) }}"
                                class="whitespace-nowrap font-semibold text-gray-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                            >
                                {{ $expense->expense_number }}
                            </a>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $expense->expense_date->format('Y-m-d') }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-gray-500 dark:text-gray-400">{{ $expense->category?->name ?: __('expenses.no_category') }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-gray-500 dark:text-gray-400">{{ $expense->vendor ?: '—' }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap font-medium tabular-nums text-gray-900 dark:text-white" dir="ltr">
                                {{ $expense->amount }} <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $expense->currency_code }}</span>
                            </span>
                        </x-ui.td>
                        <x-ui.td class="text-center">
                            @if ($expense->receipt_path)
                                <x-ui.icon name="document-text" class="mx-auto size-5 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                            @else
                                <span class="text-gray-300 dark:text-gray-600">—</span>
                            @endif
                        </x-ui.td>
                        <x-ui.td class="text-end">
                            <div class="inline-flex items-center gap-1">
                                <x-ui.icon-button
                                    variant="secondary"
                                    size="sm"
                                    icon="eye"
                                    :label="__('actions.view')"
                                    href="{{ route('expenses.show', $expense) }}"
                                />
                                @can('expenses.manage')
                                    <x-ui.icon-button
                                        variant="secondary"
                                        size="sm"
                                        icon="pencil-square"
                                        :label="__('actions.edit')"
                                        href="{{ route('expenses.edit', $expense) }}"
                                    />
                                    <x-ui.icon-button
                                        variant="danger"
                                        size="sm"
                                        icon="trash"
                                        :label="__('actions.delete')"
                                        x-on:click="$dispatch('bos:open-modal', { id: 'delete-expense-modal' }); $dispatch('bos:delete-expense', { id: {{ $expense->id }} })"
                                    />
                                @endcan
                            </div>
                        </x-ui.td>
                    </tr>
                @endforeach

                <x-slot:footer>
                    <div class="px-4 py-3">
                        <x-ui.pagination :paginator="$expenses" />
                    </div>
                </x-slot:footer>
            </x-ui.table>
        @endif
    </x-app.page>

    @can('expenses.manage')
        <x-ui.modal id="delete-expense-modal" :title="__('expenses.delete_confirm_title', ['name' => __('expenses.title')])" size="sm">
            <div x-data="deleteExpenseDialog">
                <template x-if="expenseId">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('expenses.delete_confirm') }}</p>

                        <form
                            class="mt-6 flex flex-wrap items-center justify-end gap-3"
                            method="POST"
                            x-bind:action="`/expenses/${expenseId}`"
                        >
                            @csrf
                            @method('DELETE')
                            <button
                                type="button"
                                x-on:click="close"
                                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm transition-colors duration-150 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                            >
                                {{ __('expenses.delete_cancel') }}
                            </button>
                            <x-ui.button type="submit" variant="danger" icon="trash">{{ __('expenses.delete_submit') }}</x-ui.button>
                        </form>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    @endcan
@endsection