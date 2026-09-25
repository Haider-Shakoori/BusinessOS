@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('invoices.title')"
        :subtitle="__('invoices.subtitle')"
        icon="receipt-percent"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('invoices.title')],
        ]"
    >
        @if (session('status'))
            <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
        @endif

        @cannot('invoices.manage')
            <div class="mb-5"><x-ui.alert type="info">{{ __('invoices.view_only') }}</x-ui.alert></div>
        @endcannot

        <x-ui.list-panel>
            <x-slot:actions>
                @can('invoices.manage')
                    <x-ui.button href="{{ route('invoices.create') }}" variant="outline" icon="plus">{{ __('invoices.add') }}</x-ui.button>
                @endcan
                <x-ui.button href="{{ route('invoices.export', request()->only('search', 'status')) }}" icon="arrow-down-tray">{{ __('exports.export_csv') }}</x-ui.button>
            </x-slot:actions>

            <x-slot:filters>
                <form method="GET" action="{{ route('invoices.index') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1.5fr)_minmax(190px,.7fr)_auto]" role="search">
                    <x-ui.search-input name="search" :label="__('invoices.search')" :placeholder="__('invoices.search_placeholder')" :value="$searchTerm" />

                    <x-ui.select name="status" :label="__('invoices.status_filter')" :value="$statusFilter">
                        <option value="">{{ __('invoices.statuses.all') }}</option>
                        @foreach ($statuses as $case)
                            <option value="{{ $case->value }}" @selected($statusFilter === $case->value)>{{ __('invoices.statuses.'.$case->value) }}</option>
                        @endforeach
                    </x-ui.select>

                    <div class="flex items-end gap-2">
                        <x-ui.button type="submit" icon="search">{{ __('actions.search') }}</x-ui.button>
                        @if ($searchTerm !== '' || $statusFilter !== null)
                            <x-ui.button variant="secondary" href="{{ route('invoices.index') }}">{{ __('actions.clear') }}</x-ui.button>
                        @endif
                    </div>
                </form>
            </x-slot:filters>

            @if ($invoices->isEmpty())
                <x-ui.empty-state
                    :title="$searchTerm !== '' || $statusFilter !== null ? __('invoices.no_results') : __('invoices.no_invoices')"
                    :description="$searchTerm !== '' || $statusFilter !== null ? __('invoices.no_results_description') : __('invoices.no_invoices_description')"
                    icon="receipt-percent"
                >
                    @can('invoices.manage')
                        <x-slot:actions><x-ui.button href="{{ route('invoices.create') }}" icon="plus">{{ __('invoices.add') }}</x-ui.button></x-slot:actions>
                    @endcan
                </x-ui.empty-state>
            @else
                <x-ui.table :caption="__('invoices.table_caption')" class="shadow-none">
                    <x-slot:head>
                        <tr>
                            <x-ui.th class="w-12 text-center">#</x-ui.th>
                            <x-ui.th>{{ __('invoices.columns.number') }}</x-ui.th>
                            <x-ui.th>{{ __('invoices.columns.customer') }}</x-ui.th>
                            <x-ui.th>{{ __('invoices.columns.date') }}</x-ui.th>
                            <x-ui.th>{{ __('invoices.columns.status') }}</x-ui.th>
                            <x-ui.th>{{ __('invoices.columns.source') }}</x-ui.th>
                            <x-ui.th numeric>{{ __('invoices.columns.total') }}</x-ui.th>
                            <x-ui.th class="w-16 text-center"><span class="sr-only">{{ __('invoices.columns.actions') }}</span></x-ui.th>
                        </tr>
                    </x-slot:head>

                    @foreach ($invoices as $invoice)
                        <tr>
                            <x-ui.td class="text-center text-slate-500">{{ ($invoices->firstItem() ?? 1) + $loop->index }}</x-ui.td>
                            <x-ui.td><a href="{{ route('invoices.show', $invoice) }}" class="whitespace-nowrap font-semibold text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">{{ $invoice->invoice_number }}</a></x-ui.td>
                            <x-ui.td><span class="text-slate-500 dark:text-slate-400">{{ $invoice->customer?->name ?: __('invoices.no_customer') }}</span></x-ui.td>
                            <x-ui.td><span class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $invoice->date->format('Y-m-d') }}</span></x-ui.td>
                            <x-ui.td><x-ui.status-badge :status="$invoice->status->value" :label="__('invoices.statuses.'.$invoice->status->value)" /></x-ui.td>
                            <x-ui.td>
                                @if ($invoice->quotation)
                                    <a href="{{ route('quotations.show', $invoice->quotation) }}" class="whitespace-nowrap font-medium text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300">{{ $invoice->quotation->quotation_number }}</a>
                                @else
                                    <span class="text-slate-500 dark:text-slate-400">{{ __('invoices.no_source') }}</span>
                                @endif
                            </x-ui.td>
                            <x-ui.td numeric><span class="whitespace-nowrap font-semibold">{{ $invoice->currency_code }} {{ number_format((float) $invoice->total, 4) }}</span></x-ui.td>
                            <x-ui.td class="text-center">
                                <x-ui.dropdown align="end" width="w-40" :chevron="false" label="{{ __('invoices.columns.actions') }}">
                                    <x-slot:trigger><span class="grid size-8 place-items-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"><x-ui.icon name="ellipsis-horizontal" class="size-5" /></span></x-slot:trigger>
                                    <x-slot:items>
                                        <x-ui.dropdown-item :href="route('invoices.show', $invoice)" icon="eye">{{ __('actions.view') }}</x-ui.dropdown-item>
                                        @can('invoices.manage')
                                            @if ($invoice->status === \App\Enums\InvoiceStatus::Draft)
                                                <x-ui.dropdown-item :href="route('invoices.edit', $invoice)" icon="pencil-square">{{ __('actions.edit') }}</x-ui.dropdown-item>
                                                <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10" x-on:click="close(); $dispatch('bos:open-modal', { id: 'delete-invoice-modal' }); $dispatch('bos:delete-invoice', { id: {{ $invoice->id }} })"><x-ui.icon name="trash" class="size-4" />{{ __('actions.delete') }}</button>
                                            @endif
                                        @endcan
                                    </x-slot:items>
                                </x-ui.dropdown>
                            </x-ui.td>
                        </tr>
                    @endforeach

                    <x-slot:footer><div class="px-4 py-3"><x-ui.pagination :paginator="$invoices" /></div></x-slot:footer>
                </x-ui.table>
            @endif
        </x-ui.list-panel>
    </x-app.page>

    @can('invoices.manage')
        <x-ui.modal id="delete-invoice-modal" :title="__('invoices.delete_confirm_title', ['name' => __('invoices.document')])" size="sm">
            <div x-data="deleteInvoiceDialog">
                <template x-if="invoiceId">
                    <div>
                        <p class="text-[12px] leading-5 text-slate-500 dark:text-slate-400">{{ __('invoices.delete_confirm', ['name' => '']) }}</p>
                        <form class="mt-6 flex flex-wrap items-center justify-end gap-2" method="POST" x-bind:action="`/invoices/${invoiceId}`">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="button" variant="secondary" x-on:click="close">{{ __('invoices.delete_cancel') }}</x-ui.button>
                            <x-ui.button type="submit" variant="danger" icon="trash">{{ __('invoices.delete_submit') }}</x-ui.button>
                        </form>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    @endcan
@endsection
