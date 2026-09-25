@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('quotations.title')"
        :subtitle="__('quotations.subtitle')"
        icon="document-text"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('quotations.title')],
        ]"
    >
        @if (session('status'))
            <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
        @endif

        @cannot('quotations.manage')
            <div class="mb-5"><x-ui.alert type="info">{{ __('quotations.view_only') }}</x-ui.alert></div>
        @endcannot

        <x-ui.list-panel>
            <x-slot:actions>
                @can('quotations.manage')
                    <x-ui.button href="{{ route('quotations.create') }}" variant="outline" icon="plus">{{ __('quotations.add') }}</x-ui.button>
                @endcan
            </x-slot:actions>

            <x-slot:filters>
                <form method="GET" action="{{ route('quotations.index') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1.5fr)_minmax(190px,.7fr)_auto]" role="search">
                    <x-ui.search-input name="search" :label="__('quotations.search')" :placeholder="__('quotations.search_placeholder')" :value="$searchTerm" />

                    <x-ui.select name="status" :label="__('quotations.status_filter')" :value="$statusFilter">
                        <option value="">{{ __('quotations.statuses.all') }}</option>
                        @foreach ($statuses as $case)
                            <option value="{{ $case->value }}" @selected($statusFilter === $case->value)>{{ __('quotations.statuses.'.$case->value) }}</option>
                        @endforeach
                    </x-ui.select>

                    <div class="flex items-end gap-2">
                        <x-ui.button type="submit" icon="search">{{ __('actions.search') }}</x-ui.button>
                        @if ($searchTerm !== '' || $statusFilter !== null)
                            <x-ui.button variant="secondary" href="{{ route('quotations.index') }}">{{ __('actions.clear') }}</x-ui.button>
                        @endif
                    </div>
                </form>
            </x-slot:filters>

            @if ($quotations->isEmpty())
                <x-ui.empty-state
                    :title="$searchTerm !== '' || $statusFilter !== null ? __('quotations.no_results') : __('quotations.no_quotations')"
                    :description="$searchTerm !== '' || $statusFilter !== null ? __('quotations.no_results_description') : __('quotations.no_quotations_description')"
                    icon="document-text"
                >
                    @can('quotations.manage')
                        <x-slot:actions><x-ui.button href="{{ route('quotations.create') }}" icon="plus">{{ __('quotations.add') }}</x-ui.button></x-slot:actions>
                    @endcan
                </x-ui.empty-state>
            @else
                <x-ui.table :caption="__('quotations.table_caption')" class="shadow-none">
                    <x-slot:head>
                        <tr>
                            <x-ui.th class="w-12 text-center">#</x-ui.th>
                            <x-ui.th>{{ __('quotations.columns.number') }}</x-ui.th>
                            <x-ui.th>{{ __('quotations.columns.customer') }}</x-ui.th>
                            <x-ui.th>{{ __('quotations.columns.date') }}</x-ui.th>
                            <x-ui.th>{{ __('quotations.columns.expiry_date') }}</x-ui.th>
                            <x-ui.th>{{ __('quotations.columns.status') }}</x-ui.th>
                            <x-ui.th numeric>{{ __('quotations.columns.total') }}</x-ui.th>
                            <x-ui.th class="w-16 text-center"><span class="sr-only">{{ __('quotations.columns.actions') }}</span></x-ui.th>
                        </tr>
                    </x-slot:head>

                    @foreach ($quotations as $quotation)
                        <tr>
                            <x-ui.td class="text-center text-slate-500">{{ ($quotations->firstItem() ?? 1) + $loop->index }}</x-ui.td>
                            <x-ui.td><a href="{{ route('quotations.show', $quotation) }}" class="whitespace-nowrap font-semibold text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">{{ $quotation->quotation_number }}</a></x-ui.td>
                            <x-ui.td><span class="text-slate-500 dark:text-slate-400">{{ $quotation->customer?->name ?: __('quotations.no_customer') }}</span></x-ui.td>
                            <x-ui.td><span class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $quotation->date->format('Y-m-d') }}</span></x-ui.td>
                            <x-ui.td><span class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $quotation->expiry_date?->format('Y-m-d') ?: '—' }}</span></x-ui.td>
                            <x-ui.td><x-ui.status-badge :status="$quotation->status->value" :label="__('quotations.statuses.'.$quotation->status->value)" /></x-ui.td>
                            <x-ui.td numeric><span class="whitespace-nowrap font-semibold">{{ $quotation->currency_code }} {{ number_format((float) $quotation->total, 4) }}</span></x-ui.td>
                            <x-ui.td class="text-center">
                                <x-ui.dropdown align="end" width="w-40" :chevron="false" label="{{ __('quotations.columns.actions') }}">
                                    <x-slot:trigger><span class="grid size-8 place-items-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"><x-ui.icon name="ellipsis-horizontal" class="size-5" /></span></x-slot:trigger>
                                    <x-slot:items>
                                        <x-ui.dropdown-item :href="route('quotations.show', $quotation)" icon="eye">{{ __('actions.view') }}</x-ui.dropdown-item>
                                        @can('quotations.manage')
                                            @if ($quotation->status === AppEnumsQuotationStatus::Draft)
                                                <x-ui.dropdown-item :href="route('quotations.edit', $quotation)" icon="pencil-square">{{ __('actions.edit') }}</x-ui.dropdown-item>
                                                <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10" x-on:click="close(); $dispatch('bos:open-modal', { id: 'delete-quotation-modal' }); $dispatch('bos:delete-quotation', { id: {{ $quotation->id }} })"><x-ui.icon name="trash" class="size-4" />{{ __('actions.delete') }}</button>
                                            @endif
                                        @endcan
                                    </x-slot:items>
                                </x-ui.dropdown>
                            </x-ui.td>
                        </tr>
                    @endforeach

                    <x-slot:footer><div class="px-4 py-3"><x-ui.pagination :paginator="$quotations" /></div></x-slot:footer>
                </x-ui.table>
            @endif
        </x-ui.list-panel>
    </x-app.page>

    @can('quotations.manage')
        <x-ui.modal id="delete-quotation-modal" :title="__('quotations.delete_confirm_title', ['name' => __('quotations.document')])" size="sm">
            <div x-data="deleteQuotationDialog">
                <template x-if="quotationId">
                    <div>
                        <p class="text-[12px] leading-5 text-slate-500 dark:text-slate-400">{{ __('quotations.delete_confirm', ['name' => '']) }}</p>
                        <form class="mt-6 flex flex-wrap items-center justify-end gap-2" method="POST" x-bind:action="`/quotations/${quotationId}`">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="button" variant="secondary" x-on:click="close">{{ __('quotations.delete_cancel') }}</x-ui.button>
                            <x-ui.button type="submit" variant="danger" icon="trash">{{ __('quotations.delete_submit') }}</x-ui.button>
                        </form>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    @endcan
@endsection
