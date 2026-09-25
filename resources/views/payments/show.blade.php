@extends('layouts.app')

@section('content')
    @php
        $invoice = $payment->allocations->first()?->invoice;
        $customer = $invoice?->customer;
    @endphp

    <x-app.page
        :title="$payment->payment_number"
        :subtitle="__('payments.document')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('payments.title'), 'url' => route('payments.index')],
            ['label' => $payment->payment_number],
        ]"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('payments.index') }}" icon="arrow-right">
                {{ __('payments.view_all') }}
            </x-ui.button>

            @if ($invoice)
                <x-ui.button variant="secondary" href="{{ route('invoices.show', $invoice) }}" icon="receipt-percent">
                    {{ __('payments.back_to_invoice') }}
                </x-ui.button>
            @endif

            @can('payments.reverse')
                @if ($payment->reversed_at === null)
                    <x-ui.button
                        variant="danger"
                        icon="x-circle"
                        x-on:click="$dispatch('bos:open-modal', { id: 'reverse-payment-modal' }); $dispatch('bos:reverse-payment', { id: {{ $payment->id }}, number: '{{ $payment->payment_number }}' })"
                    >
                        {{ __('payments.reverse_submit') }}
                    </x-ui.button>
                @endif
            @endcan
        </x-slot:actions>

        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-1">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('payments.document') }}</h2>
                    </x-slot:header>

                    <dl class="space-y-4">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('payments.number') }}</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white" dir="ltr">{{ $payment->payment_number }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('payments.invoice') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">
                                @if ($invoice)
                                    <a href="{{ route('invoices.show', $invoice) }}" class="text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300">
                                        {{ $invoice->invoice_number }}
                                    </a>
                                @else
                                    {{ __('payments.no_invoice') }}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('payments.customer') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">
                                @if ($customer)
                                    <a href="{{ route('customers.show', $customer) }}" class="text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300">
                                        {{ $customer->name }}
                                    </a>
                                @else
                                    {{ __('payments.no_customer') }}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('payments.date') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $payment->payment_date->format('Y-m-d') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('payments.method') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ __('payments.methods.'.$payment->payment_method) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('currencies.currency') }}</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white" dir="ltr">{{ $payment->currency_code }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('payments.amount') }}</dt>
                            <dd class="mt-1 text-base font-semibold tabular-nums text-slate-900 dark:text-white" dir="ltr">
                                {{ $payment->amount }} <span class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $payment->currency_code }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('payments.status') }}</dt>
                            <dd class="mt-1">
                                <x-ui.status-badge
                                    :status="$payment->reversed_at === null ? 'active' : 'reversed'"
                                    :label="__('payments.statuses.'.($payment->reversed_at === null ? 'active' : 'reversed'))"
                                />
                            </dd>
                        </div>
                    </dl>
                </x-ui.card>
            </div>

            <div class="lg:col-span-2">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('payments.information') }}</h2>
                    </x-slot:header>

                    <dl class="space-y-4">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('payments.reference') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $payment->reference ?: __('payments.no_reference') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('payments.notes') }}</dt>
                            <dd class="mt-1 text-sm whitespace-pre-line text-slate-900 dark:text-slate-100">{{ $payment->notes ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('payments.recorded_by') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $payment->createdBy?->name ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('payments.recorded_at') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $payment->created_at?->format('Y-m-d H:i') }}</dd>
                        </div>
                        @if ($payment->reversed_at !== null)
                            <div class="rounded-lg border border-red-200 bg-red-50 p-4 dark:border-red-500/30 dark:bg-red-500/10">
                                <div class="flex items-start gap-3">
                                    <x-ui.icon name="x-circle" class="mt-0.5 size-5 shrink-0 text-red-500" />
                                    <div class="space-y-2">
                                        <p class="text-sm font-semibold text-red-700 dark:text-red-300">{{ __('payments.statuses.reversed') }}</p>
                                        <dl class="space-y-1 text-sm text-red-700/90 dark:text-red-300/90">
                                            <div class="flex gap-2">
                                                <dt class="whitespace-nowrap">{{ __('payments.reversed_by') }}:</dt>
                                                <dd>{{ $payment->reversedBy?->name ?: '—' }}</dd>
                                            </div>
                                            <div class="flex gap-2">
                                                <dt class="whitespace-nowrap">{{ __('payments.reversed_at') }}:</dt>
                                                <dd>{{ $payment->reversed_at?->format('Y-m-d H:i') }}</dd>
                                            </div>
                                            @if ($payment->reversal_reason)
                                                <div class="flex gap-2">
                                                    <dt class="whitespace-nowrap">{{ __('payments.reversal_reason_label') }}:</dt>
                                                    <dd class="whitespace-pre-line">{{ $payment->reversal_reason }}</dd>
                                                </div>
                                            @endif
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </dl>
                </x-ui.card>
            </div>
        </div>
    </x-app.page>

    @can('payments.reverse')
        @if ($payment->reversed_at === null)
            <div x-data="reversePaymentDialog">
                @php
                    $reverseTitle = new \Illuminate\Support\HtmlString(
                        __('payments.reverse_title', ['name' => '<span x-text="paymentNumber ?? \'\'"></span>'])
                    );
                    $reverseConfirm = new \Illuminate\Support\HtmlString(
                        __('payments.reverse_confirm', ['name' => '<span x-text="paymentNumber ?? \'\'" class="font-semibold text-slate-900 dark:text-white"></span>'])
                    );
                @endphp

                <x-ui.modal id="reverse-payment-modal" :title="$reverseTitle" size="sm">
                    <template x-if="paymentId">
                        <div>
                            <p class="text-sm text-slate-500 dark:text-slate-400">{!! $reverseConfirm !!}</p>

                            <form
                                class="mt-6 space-y-4"
                                method="POST"
                                x-bind:action="`/payments/${paymentId}/reverse`"
                            >
                                @csrf
                                <div>
                                    <label for="reversal-reason" class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">
                                        {{ __('payments.reversal_reason_label') }}
                                    </label>
                                    <textarea
                                        id="reversal-reason"
                                        name="reversal_reason"
                                        rows="3"
                                        placeholder="{{ __('payments.reversal_reason_placeholder') }}"
                                        class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm
                                            placeholder:text-slate-400 transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30
                                            dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500"
                                    ></textarea>
                                </div>

                                <div class="flex flex-wrap items-center justify-end gap-3">
                                    <button
                                        type="button"
                                        x-on:click="close"
                                        class="inline-flex shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition-colors duration-150 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                                    >
                                        {{ __('payments.reverse_cancel') }}
                                    </button>
                                    <x-ui.button type="submit" variant="danger" icon="x-circle">{{ __('payments.reverse_submit') }}</x-ui.button>
                                </div>
                            </form>
                        </div>
                    </template>
                </x-ui.modal>
            </div>
        @endif
    @endcan
@endsection