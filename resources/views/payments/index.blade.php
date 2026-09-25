@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('payments.title')"
        :subtitle="__('payments.subtitle')"
        icon="banknotes"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('payments.title')],
        ]"
    >
        @if (session('status'))
            <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
        @endif

        @cannot('payments.create')
            <div class="mb-5"><x-ui.alert type="info">{{ __('payments.view_only') }}</x-ui.alert></div>
        @endcannot

        <x-ui.list-panel>
            <x-slot:actions>
                <x-ui.button variant="outline" href="{{ route('invoices.index') }}" icon="receipt-percent">{{ __('invoices.view_all') }}</x-ui.button>
            </x-slot:actions>

            <x-slot:filters>
                <form method="GET" action="{{ route('payments.index') }}" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto]" role="search">
                    <x-ui.search-input name="search" :label="__('payments.search')" :placeholder="__('payments.search_placeholder')" :value="$searchTerm" />
                    <div class="flex items-end gap-2">
                        <x-ui.button type="submit" icon="search">{{ __('actions.search') }}</x-ui.button>
                        @if ($searchTerm !== '')<x-ui.button variant="secondary" href="{{ route('payments.index') }}">{{ __('actions.clear') }}</x-ui.button>@endif
                    </div>
                </form>
            </x-slot:filters>

            @if ($payments->isEmpty())
                <x-ui.empty-state
                    :title="$searchTerm !== '' ? __('payments.no_results') : __('payments.no_payments')"
                    :description="$searchTerm !== '' ? __('payments.no_results_description') : __('payments.no_payments_description')"
                    icon="banknotes"
                />
            @else
                <x-ui.table :caption="__('payments.table_caption')" class="shadow-none">
                    <x-slot:head>
                        <tr>
                            <x-ui.th class="w-12 text-center">#</x-ui.th>
                            <x-ui.th>{{ __('payments.columns.number') }}</x-ui.th>
                            <x-ui.th>{{ __('payments.columns.invoice') }}</x-ui.th>
                            <x-ui.th>{{ __('payments.columns.customer') }}</x-ui.th>
                            <x-ui.th>{{ __('payments.columns.date') }}</x-ui.th>
                            <x-ui.th>{{ __('payments.columns.method') }}</x-ui.th>
                            <x-ui.th>{{ __('payments.columns.status') }}</x-ui.th>
                            <x-ui.th numeric>{{ __('payments.columns.amount') }}</x-ui.th>
                            <x-ui.th class="w-16 text-center"><span class="sr-only">{{ __('payments.columns.actions') }}</span></x-ui.th>
                        </tr>
                    </x-slot:head>

                    @foreach ($payments as $payment)
                        @php
                            $invoice = $payment->allocations->first()?->invoice;
                            $customer = $invoice?->customer;
                        @endphp
                        <tr>
                            <x-ui.td class="text-center text-slate-500">{{ ($payments->firstItem() ?? 1) + $loop->index }}</x-ui.td>
                            <x-ui.td><a href="{{ route('payments.show', $payment) }}" class="whitespace-nowrap font-semibold text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">{{ $payment->payment_number }}</a></x-ui.td>
                            <x-ui.td>
                                @if ($invoice)
                                    <a href="{{ route('invoices.show', $invoice) }}" class="whitespace-nowrap font-medium text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300">{{ $invoice->invoice_number }}</a>
                                @else
                                    <span class="text-slate-500 dark:text-slate-400">{{ __('payments.no_invoice') }}</span>
                                @endif
                            </x-ui.td>
                            <x-ui.td><span class="text-slate-500 dark:text-slate-400">{{ $customer?->name ?: __('payments.no_customer') }}</span></x-ui.td>
                            <x-ui.td><span class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $payment->payment_date->format('Y-m-d') }}</span></x-ui.td>
                            <x-ui.td><span class="text-slate-500 dark:text-slate-400">{{ __('payments.methods.'.$payment->payment_method) }}</span></x-ui.td>
                            <x-ui.td><x-ui.status-badge :status="$payment->reversed_at === null ? 'active' : 'reversed'" :label="__('payments.statuses.'.($payment->reversed_at === null ? 'active' : 'reversed'))" /></x-ui.td>
                            <x-ui.td numeric><span class="whitespace-nowrap font-semibold">{{ $payment->currency_code }} {{ number_format((float) $payment->amount, 4) }}</span></x-ui.td>
                            <x-ui.td class="text-center">
                                <x-ui.dropdown align="end" width="w-36" :chevron="false" label="{{ __('payments.columns.actions') }}">
                                    <x-slot:trigger><span class="grid size-8 place-items-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"><x-ui.icon name="ellipsis-horizontal" class="size-5" /></span></x-slot:trigger>
                                    <x-slot:items><x-ui.dropdown-item :href="route('payments.show', $payment)" icon="eye">{{ __('actions.view') }}</x-ui.dropdown-item></x-slot:items>
                                </x-ui.dropdown>
                            </x-ui.td>
                        </tr>
                    @endforeach

                    <x-slot:footer><div class="px-4 py-3"><x-ui.pagination :paginator="$payments" /></div></x-slot:footer>
                </x-ui.table>
            @endif
        </x-ui.list-panel>
    </x-app.page>
@endsection
