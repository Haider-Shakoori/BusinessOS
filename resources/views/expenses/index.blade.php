@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('expenses.title')"
        :subtitle="__('expenses.subtitle')"
        icon="arrow-trending-down"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('expenses.title')],
        ]"
    >
        @if (session('status'))
            <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
        @endif

        @cannot('expenses.manage')
            <div class="mb-5"><x-ui.alert type="info">{{ __('expenses.view_only') }}</x-ui.alert></div>
        @endcannot

        <x-ui.list-panel>
            <x-slot:actions>
                @can('expenses.manage')
                    <x-ui.button href="{{ route('expenses.create') }}" variant="outline" icon="plus">{{ __('expenses.add') }}</x-ui.button>
                @endcan
                <x-ui.button variant="outline" href="{{ route('expenses.report') }}" icon="chart-bar">{{ __('expenses.report') }}</x-ui.button>
                <x-ui.button href="{{ route('expenses.export', request()->only('search', 'category_id', 'date_from', 'date_to')) }}" icon="arrow-down-tray">{{ __('exports.export_csv') }}</x-ui.button>
            </x-slot:actions>

            <x-slot:filters>
                <form method="GET" action="{{ route('expenses.index') }}" role="search" class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1.3fr)_minmax(180px,.8fr)_minmax(150px,.65fr)_minmax(150px,.65fr)_auto]">
                    <x-ui.search-input name="search" :label="__('expenses.search')" :placeholder="__('expenses.search_placeholder')" :value="$searchTerm" />

                    <x-ui.select name="category_id" :label="__('expenses.category_filter')" :value="$categoryId">
                        <option value="">{{ __('expenses.all_categories') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) $categoryId === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input name="date_from" type="date" :label="__('expenses.date_from')" :value="$dateFrom" />
                    <x-ui.input name="date_to" type="date" :label="__('expenses.date_to')" :value="$dateTo" />

                    <div class="flex items-end gap-2">
                        <x-ui.button type="submit" icon="search">{{ __('actions.search') }}</x-ui.button>
                        @if ($searchTerm !== '' || $categoryId !== null || $dateFrom !== null || $dateTo !== null)
                            <x-ui.button variant="secondary" href="{{ route('expenses.index') }}">{{ __('actions.clear') }}</x-ui.button>
                        @endif
                    </div>
                </form>
            </x-slot:filters>

            @if ($expenses->isEmpty())
                <x-ui.empty-state
                    :title="$searchTerm !== '' || $categoryId !== null || $dateFrom !== null || $dateTo !== null ? __('expenses.no_results') : __('expenses.no_expenses')"
                    :description="$searchTerm !== '' || $categoryId !== null || $dateFrom !== null || $dateTo !== null ? __('expenses.no_results_description') : __('expenses.no_expenses_description')"
                    icon="arrow-trending-down"
                >
                    @can('expenses.manage')
                        <x-slot:actions><x-ui.button href="{{ route('expenses.create') }}" icon="plus">{{ __('expenses.add') }}</x-ui.button></x-slot:actions>
                    @endcan
                </x-ui.empty-state>
            @else
                <x-ui.table :caption="__('expenses.table_caption')" class="shadow-none">
                    <x-slot:head>
                        <tr>
                            <x-ui.th class="w-12 text-center">#</x-ui.th>
                            <x-ui.th>{{ __('expenses.columns.number') }}</x-ui.th>
                            <x-ui.th>{{ __('expenses.columns.date') }}</x-ui.th>
                            <x-ui.th>{{ __('expenses.columns.category') }}</x-ui.th>
                            <x-ui.th>{{ __('expenses.columns.vendor') }}</x-ui.th>
                            <x-ui.th numeric>{{ __('expenses.columns.amount') }}</x-ui.th>
                            <x-ui.th class="text-center">{{ __('expenses.columns.receipt') }}</x-ui.th>
                            <x-ui.th class="w-16 text-center"><span class="sr-only">{{ __('expenses.columns.actions') }}</span></x-ui.th>
                        </tr>
                    </x-slot:head>

                    @foreach ($expenses as $expense)
                        <tr>
                            <x-ui.td class="text-center text-slate-500">{{ ($expenses->firstItem() ?? 1) + $loop->index }}</x-ui.td>
                            <x-ui.td><a href="{{ route('expenses.show', $expense) }}" class="whitespace-nowrap font-semibold text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">{{ $expense->expense_number }}</a></x-ui.td>
                            <x-ui.td><span class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $expense->expense_date->format('Y-m-d') }}</span></x-ui.td>
                            <x-ui.td><span class="text-slate-500 dark:text-slate-400">{{ $expense->category?->name ?: __('expenses.no_category') }}</span></x-ui.td>
                            <x-ui.td><span class="text-slate-500 dark:text-slate-400">{{ $expense->vendor ?: '—' }}</span></x-ui.td>
                            <x-ui.td numeric><span class="whitespace-nowrap font-semibold">{{ $expense->currency_code }} {{ number_format((float) $expense->amount, 4) }}</span></x-ui.td>
                            <x-ui.td class="text-center">
                                @if ($expense->receipt_path)
                                    <span class="inline-flex size-8 items-center justify-center rounded-[7px] bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                                        <x-ui.icon name="document-text" class="size-4" aria-hidden="true" />
                                    </span>
                                @else
                                    <span class="text-slate-300 dark:text-slate-600">—</span>
                                @endif
                            </x-ui.td>
                            <x-ui.td class="text-center">
                                <x-ui.dropdown align="end" width="w-40" :chevron="false" label="{{ __('expenses.columns.actions') }}">
                                    <x-slot:trigger><span class="grid size-8 place-items-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"><x-ui.icon name="ellipsis-horizontal" class="size-5" /></span></x-slot:trigger>
                                    <x-slot:items>
                                        <x-ui.dropdown-item :href="route('expenses.show', $expense)" icon="eye">{{ __('actions.view') }}</x-ui.dropdown-item>
                                        @can('expenses.manage')
                                            <x-ui.dropdown-item :href="route('expenses.edit', $expense)" icon="pencil-square">{{ __('actions.edit') }}</x-ui.dropdown-item>
                                            <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10" x-on:click="close(); $dispatch('bos:open-modal', { id: 'delete-expense-modal' }); $dispatch('bos:delete-expense', { id: {{ $expense->id }} })"><x-ui.icon name="trash" class="size-4" />{{ __('actions.delete') }}</button>
                                        @endcan
                                    </x-slot:items>
                                </x-ui.dropdown>
                            </x-ui.td>
                        </tr>
                    @endforeach

                    <x-slot:footer><div class="px-4 py-3"><x-ui.pagination :paginator="$expenses" /></div></x-slot:footer>
                </x-ui.table>
            @endif
        </x-ui.list-panel>
    </x-app.page>

    @can('expenses.manage')
        <x-ui.modal id="delete-expense-modal" :title="__('expenses.delete_confirm_title', ['name' => __('expenses.title')])" size="sm">
            <div x-data="deleteExpenseDialog">
                <template x-if="expenseId">
                    <div>
                        <p class="text-[12px] leading-5 text-slate-500 dark:text-slate-400">{{ __('expenses.delete_confirm') }}</p>
                        <form class="mt-6 flex flex-wrap items-center justify-end gap-2" method="POST" x-bind:action="`/expenses/${expenseId}`">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="button" variant="secondary" x-on:click="close">{{ __('expenses.delete_cancel') }}</x-ui.button>
                            <x-ui.button type="submit" variant="danger" icon="trash">{{ __('expenses.delete_submit') }}</x-ui.button>
                        </form>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    @endcan
@endsection
