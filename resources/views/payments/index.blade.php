@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('payments.title')"
        :subtitle="__('payments.subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('payments.title')],
        ]"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('invoices.index') }}" icon="receipt-percent">
                {{ __('invoices.view_all') }}
            </x-ui.button>
        </x-slot:actions>

        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        @cannot('payments.create')
            <div class="mb-6">
                <x-ui.alert type="info">{{ __('payments.view_only') }}</x-ui.alert>
            </div>
        @endcannot

        <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <form method="GET" action="{{ route('payments.index') }}" class="lg:max-w-md lg:flex-1" role="search">
                <label for="payment-search" class="sr-only">{{ __('payments.search') }}</label>
                <div class="relative max-w-md">
                    <x-ui.icon
                        name="search"
                        class="pointer-events-none absolute inset-y-0 start-0 my-auto ms-3 size-4 text-gray-400 dark:text-gray-500"
                        aria-hidden="true"
                    />
                    <input
                        id="payment-search"
                        type="search"
                        name="search"
                        value="{{ $searchTerm }}"
                        placeholder="{{ __('payments.search_placeholder') }}"
                        class="block w-full rounded-lg border border-gray-300 bg-white py-2 pe-16 ps-10 text-sm text-gray-900 shadow-sm
                            placeholder:text-gray-400 transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30
                            dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-brand-400"
                    />
                    @if ($searchTerm !== '')
                        <a
                            href="{{ route('payments.index') }}"
                            class="absolute inset-y-0 end-0 flex items-center pe-2.5 text-sm font-medium text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300"
                        >
                            {{ __('actions.clear') }}
                        </a>
                    @endif
                </div>
                <input type="submit" value="{{ __('actions.search') }}" class="sr-only" />
            </form>
        </div>

        @if ($payments->isEmpty())
            <x-ui.card>
                <x-ui.empty-state
                    :title="$searchTerm !== '' ? __('payments.no_results') : __('payments.no_payments')"
                    :description="$searchTerm !== '' ? __('payments.no_results_description') : __('payments.no_payments_description')"
                    icon="banknotes"
                />
            </x-ui.card>
        @else
            <x-ui.table :caption="__('payments.table_caption')">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('payments.columns.number') }}</x-ui.th>
                        <x-ui.th>{{ __('payments.columns.invoice') }}</x-ui.th>
                        <x-ui.th>{{ __('payments.columns.customer') }}</x-ui.th>
                        <x-ui.th>{{ __('payments.columns.date') }}</x-ui.th>
                        <x-ui.th>{{ __('payments.columns.method') }}</x-ui.th>
                        <x-ui.th>{{ __('payments.columns.status') }}</x-ui.th>
                        <x-ui.th class="text-end">{{ __('payments.columns.amount') }}</x-ui.th>
                        <x-ui.th class="text-end">
                            <span class="sr-only">{{ __('payments.columns.actions') }}</span>
                        </x-ui.th>
                    </tr>
                </x-slot:head>

                @foreach ($payments as $payment)
                    @php
                        $invoice = $payment->allocations->first()?->invoice;
                        $customer = $invoice?->customer;
                    @endphp
                    <tr>
                        <x-ui.td>
                            <a
                                href="{{ route('payments.show', $payment) }}"
                                class="whitespace-nowrap font-semibold text-gray-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                            >
                                {{ $payment->payment_number }}
                            </a>
                        </x-ui.td>
                        <x-ui.td>
                            @if ($invoice)
                                <a
                                    href="{{ route('invoices.show', $invoice) }}"
                                    class="whitespace-nowrap font-medium text-brand-600 transition-colors hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300"
                                >
                                    {{ $invoice->invoice_number }}
                                </a>
                            @else
                                <span class="text-gray-500 dark:text-gray-400">{{ __('payments.no_invoice') }}</span>
                            @endif
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-gray-500 dark:text-gray-400">{{ $customer?->name ?: __('payments.no_customer') }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $payment->payment_date->format('Y-m-d') }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-gray-500 dark:text-gray-400">{{ __('payments.methods.'.$payment->payment_method) }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <x-ui.status-badge
                                :status="$payment->reversed_at === null ? 'active' : 'reversed'"
                                :label="__('payments.statuses.'.($payment->reversed_at === null ? 'active' : 'reversed'))"
                            />
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap font-medium tabular-nums text-gray-900 dark:text-white">
                                {{ $payment->amount }} <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $payment->currency_code }}</span>
                            </span>
                        </x-ui.td>
                        <x-ui.td class="text-end">
                            <div class="inline-flex items-center gap-1">
                                <x-ui.icon-button
                                    variant="secondary"
                                    size="sm"
                                    icon="eye"
                                    :label="__('actions.view')"
                                    href="{{ route('payments.show', $payment) }}"
                                />
                            </div>
                        </x-ui.td>
                    </tr>
                @endforeach

                <x-slot:footer>
                    <div class="px-4 py-3">
                        <x-ui.pagination :paginator="$payments" />
                    </div>
                </x-slot:footer>
            </x-ui.table>
        @endif
    </x-app.page>
@endsection