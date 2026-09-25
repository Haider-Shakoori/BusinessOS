@extends('layouts.app')

@section('content')
    <x-app.page icon="receipt-percent"
        :title="$invoice->invoice_number"
        :subtitle="__('invoices.document')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('invoices.title'), 'url' => route('invoices.index')],
            ['label' => $invoice->invoice_number],
        ]"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('invoices.index') }}" icon="arrow-right">
                {{ __('invoices.view_all') }}
            </x-ui.button>

            <x-ui.button
                variant="secondary"
                href="{{ route('invoices.print', $invoice) }}"
                target="_blank"
                rel="noopener"
                icon="receipt-percent"
            >
                {{ __('invoices.print') }}
            </x-ui.button>

            @can('payments.create')
                @if ($payable)
                    <x-ui.button
                        icon="banknotes"
                        x-on:click="$dispatch('bos:open-modal', { id: 'record-payment-modal' })"
                    >
                        {{ __('payments.record') }}
                    </x-ui.button>
                @endif
            @endcan

            @can('invoices.manage')
                @if ($editable)
                    <x-ui.button href="{{ route('invoices.edit', $invoice) }}" icon="pencil-square">
                        {{ __('actions.edit') }}
                    </x-ui.button>
                    <x-ui.button
                        variant="danger"
                        icon="trash"
                        x-on:click="$dispatch('bos:open-modal', { id: 'delete-invoice-modal' })"
                    >
                        {{ __('actions.delete') }}
                    </x-ui.button>
                @endif
            @endcan
        </x-slot:actions>

        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        @if (! $editable)
            <div class="mb-6">
                <x-ui.alert type="info">{{ __('invoices.read_only') }}</x-ui.alert>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('invoices.line_items') }}</h2>
                            <x-ui.status-badge :status="$invoice->status->value" :label="__('invoices.statuses.'.$invoice->status->value)" />
                        </div>
                    </x-slot:header>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
                            <thead>
                                <tr>
                                    <x-ui.th>{{ __('invoices.product') }}</x-ui.th>
                                    <x-ui.th>{{ __('invoices.description') }}</x-ui.th>
                                    <x-ui.th class="text-end">{{ __('invoices.quantity') }}</x-ui.th>
                                    <x-ui.th class="text-end">{{ __('invoices.unit_price') }}</x-ui.th>
                                    @if ($invoice->tax_amount > 0)
                                        <x-ui.th class="text-end">{{ __('invoices.tax') }}</x-ui.th>
                                    @endif
                                    <x-ui.th class="text-end">{{ __('invoices.amount') }}</x-ui.th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($invoice->items as $item)
                                    <tr>
                                        <x-ui.td>
                                            <span class="text-slate-500 dark:text-slate-400">{{ $item->product?->name ?: __('invoices.no_product') }}</span>
                                        </x-ui.td>
                                        <x-ui.td>
                                            <span class="text-slate-900 dark:text-slate-100">{{ $item->description }}</span>
                                        </x-ui.td>
                                        <x-ui.td class="text-end">
                                            <span class="whitespace-nowrap tabular-nums text-slate-500 dark:text-slate-400">{{ $item->quantity }}</span>
                                        </x-ui.td>
                                        <x-ui.td class="text-end">
                                            <span class="whitespace-nowrap tabular-nums text-slate-900 dark:text-slate-100">{{ $item->unit_price }}</span>
                                        </x-ui.td>
                                        @if ($invoice->tax_amount > 0)
                                            <x-ui.td class="text-end">
                                                <span class="whitespace-nowrap tabular-nums text-slate-500 dark:text-slate-400">
                                                    @if ($item->tax)
                                                        {{ $item->tax->name }} ({{ $item->tax_rate }}%)
                                                    @else
                                                        —
                                                    @endif
                                                </span>
                                            </x-ui.td>
                                        @endif
                                        <x-ui.td class="text-end">
                                            <span class="whitespace-nowrap font-medium tabular-nums text-slate-900 dark:text-white">{{ $item->line_total }}</span>
                                        </x-ui.td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-6 flex justify-end">
                        <dl class="w-full max-w-xs space-y-2 text-sm">
<div class="flex items-center justify-between gap-4">
                                <dt class="text-base font-semibold text-slate-900 dark:text-white">{{ __('invoices.total') }}</dt>
                                <dd class="whitespace-nowrap text-base font-semibold tabular-nums text-slate-900 dark:text-white" dir="ltr">{{ $invoice->total }}</dd>
                            </div>
                            @if ($invoice->currency_code !== $baseCurrency)
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('currencies.base_amount', ['currency' => $baseCurrency]) }}</dt>
                                    <dd class="whitespace-nowrap text-xs tabular-nums text-slate-500 dark:text-slate-400" dir="ltr">{{ $invoice->base_amount }}</dd>
                                </div>
                                <p class="text-xs text-slate-400 dark:text-slate-500" dir="ltr">
                                    {{ __('currencies.rate_applied', ['rate' => $invoice->exchange_rate, 'currency' => $invoice->currency_code, 'base' => $baseCurrency]) }}
                                </p>
                            @endif
                            @if ($invoice->discount_type && $invoice->discount_amount > 0)
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-slate-500 dark:text-slate-400">
                                        {{ __('invoices.discount') }}
                                        @if ($invoice->discount_type === 'percentage')
                                            ({{ $invoice->discount_amount }}%)
                                        @endif
                                    </dt>
                                    <dd class="whitespace-nowrap tabular-nums text-slate-900 dark:text-slate-100" dir="ltr">−{{ $invoice->discount_amount }}</dd>
                                </div>
                            @endif
                            @if ($invoice->tax_amount > 0)
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-slate-500 dark:text-slate-400">{{ __('invoices.tax_amount') }}</dt>
                                    <dd class="whitespace-nowrap tabular-nums text-slate-900 dark:text-slate-100" dir="ltr">{{ $invoice->tax_amount }}</dd>
                                </div>
                            @endif
                            <div class="flex items-center justify-between gap-4 border-t border-slate-200 pt-2 dark:border-slate-700">
                                <dt class="text-base font-semibold text-slate-900 dark:text-white">{{ __('invoices.total') }}</dt>
                                <dd class="whitespace-nowrap text-base font-semibold tabular-nums text-slate-900 dark:text-white" dir="ltr">{{ $invoice->total }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-slate-500 dark:text-slate-400">{{ __('payments.amount_paid') }}</dt>
                                <dd class="whitespace-nowrap tabular-nums text-emerald-600 dark:text-emerald-400" dir="ltr">{{ $invoice->amount_paid }}</dd>
                            </div>
                            @if ($invoice->amount_due > 0)
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-slate-500 dark:text-slate-500">{{ __('payments.amount_due') }}</dt>
                                    <dd class="whitespace-nowrap tabular-nums text-slate-900 dark:text-slate-100" dir="ltr">{{ $invoice->amount_due }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                </x-ui.card>

                @if ($payments->isNotEmpty())
                    <div class="mt-6">
                        <x-ui.card>
                            <x-slot:header>
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('payments.payment_history') }}</h2>
                                    <span class="text-sm text-slate-500 dark:text-slate-400">{{ trans_choice('payments.payment_count', $payments->count(), ['count' => $payments->count()]) }}</span>
                                </div>
                            </x-slot:header>

                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
                                    <thead>
                                        <tr>
                                            <x-ui.th>{{ __('payments.number') }}</x-ui.th>
                                            <x-ui.th>{{ __('payments.date') }}</x-ui.th>
                                            <x-ui.th>{{ __('payments.method') }}</x-ui.th>
                                            <x-ui.th>{{ __('payments.status') }}</x-ui.th>
                                            <x-ui.th class="text-end">{{ __('payments.amount') }}</x-ui.th>
                                            <x-ui.th class="text-end">
                                                <span class="sr-only">{{ __('payments.columns.actions') }}</span>
                                            </x-ui.th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                        @foreach ($payments as $payment)
                                            <tr>
                                                <x-ui.td>
                                                    <a
                                                        href="{{ route('payments.show', $payment) }}"
                                                        class="whitespace-nowrap font-semibold text-slate-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                                                    >
                                                        {{ $payment->payment_number }}
                                                    </a>
                                                </x-ui.td>
                                                <x-ui.td>
                                                    <span class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $payment->payment_date->format('Y-m-d') }}</span>
                                                </x-ui.td>
                                                <x-ui.td>
                                                    <span class="text-slate-500 dark:text-slate-400">{{ __('payments.methods.'.$payment->payment_method) }}</span>
                                                </x-ui.td>
                                                <x-ui.td>
                                                    <x-ui.status-badge
                                                        :status="$payment->reversed_at === null ? 'active' : 'reversed'"
                                                        :label="__('payments.statuses.'.($payment->reversed_at === null ? 'active' : 'reversed'))"
                                                    />
                                                </x-ui.td>
                                                <x-ui.td class="text-end">
                                                    <span class="whitespace-nowrap font-medium tabular-nums text-slate-900 dark:text-white">{{ $payment->amount }}</span>
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
                                                        @can('payments.reverse')
                                                            @if ($payment->reversed_at === null)
                                                                <x-ui.icon-button
                                                                    variant="danger"
                                                                    size="sm"
                                                                    icon="x-circle"
                                                                    :label="__('payments.reverse_submit')"
                                                                    x-on:click="$dispatch('bos:open-modal', { id: 'reverse-payment-modal' }); $dispatch('bos:reverse-payment', { id: {{ $payment->id }}, number: '{{ $payment->payment_number }}' })"
                                                                />
                                                            @endif
                                                        @endcan
                                                    </div>
                                                </x-ui.td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </x-ui.card>
                    </div>
                @endif

                @if ($invoice->notes)
                    <div class="mt-6">
                        <x-ui.card>
                            <x-slot:header>
                                <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('invoices.notes') }}</h2>
                            </x-slot:header>
                            <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $invoice->notes }}</p>
                        </x-ui.card>
                    </div>
                @endif
            </div>

            <div class="lg:col-span-1">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('invoices.information') }}</h2>
                    </x-slot:header>

                    <dl class="space-y-4">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('invoices.number') }}</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white" dir="ltr">{{ $invoice->invoice_number }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('invoices.customer') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">
                                @if ($invoice->customer)
                                    <a href="{{ route('customers.show', $invoice->customer) }}" class="text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300">
                                        {{ $invoice->customer->name }}
                                    </a>
                                @else
                                    {{ __('invoices.no_customer') }}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('invoices.date') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $invoice->date->format('Y-m-d') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('currencies.currency') }}</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white" dir="ltr">{{ $invoice->currency_code }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('invoices.status') }}</dt>
                            <dd class="mt-1">
                                <x-ui.status-badge :status="$invoice->status->value" :label="__('invoices.statuses.'.$invoice->status->value)" />
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('invoices.source') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">
                                @if ($invoice->quotation)
                                    <a href="{{ route('quotations.show', $invoice->quotation) }}" class="text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300">
                                        {{ $invoice->quotation->quotation_number }}
                                    </a>
                                @else
                                    {{ __('invoices.no_source') }}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('invoices.created_by') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $invoice->createdBy?->name ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('invoices.created_at') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $invoice->created_at?->format('Y-m-d H:i') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('invoices.updated_at') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $invoice->updated_at?->format('Y-m-d H:i') }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            </div>
        </div>
    </x-app.page>

    @can('invoices.manage')
        @if ($editable)
            <x-ui.modal id="delete-invoice-modal" :title="__('invoices.delete_confirm_title', ['name' => $invoice->invoice_number])" size="sm">
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ __('invoices.delete_confirm', ['name' => $invoice->invoice_number]) }}
                </p>

                <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" class="mt-6 flex flex-wrap items-center justify-end gap-3">
                    @csrf
                    @method('DELETE')
                    <button
                        type="button"
                        x-on:click="close"
                        class="inline-flex shrink-0 items-center justify-center rounded-[7px] border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition-colors duration-150 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                    >
                        {{ __('invoices.delete_cancel') }}
                    </button>
                    <x-ui.button type="submit" variant="danger" icon="trash">{{ __('invoices.delete_submit') }}</x-ui.button>
                </form>
            </x-ui.modal>
        @endif
    @endcan

    @can('payments.create')
        @if ($payable)
            <x-ui.modal id="record-payment-modal" :title="__('payments.record_title', ['invoice' => $invoice->invoice_number])" :description="__('payments.record_description', ['amount' => $invoice->amount_due])" size="md">
                <form method="POST" action="{{ route('payments.store') }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="invoice_id" value="{{ $invoice->id }}" />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-ui.input
                                type="number"
                                name="amount"
                                label="{{ __('payments.amount') }}"
                                placeholder="0.00"
                                min="0"
                                step="0.0001"
                                inputmode="decimal"
                                required
                            />
                        </div>
                        <div>
                            <x-ui.input
                                type="date"
                                name="payment_date"
                                label="{{ __('payments.payment_date') }}"
                                value="{{ \Illuminate\Support\Carbon::today()->toDateString() }}"
                                required
                            />
                        </div>
                    </div>

                    <div>
                        <x-ui.select name="payment_method" label="{{ __('payments.payment_method') }}" required>
                            @foreach (\App\Enums\PaymentMethod::cases() as $method)
                                <option value="{{ $method->value }}">
                                    {{ __('payments.methods.'.$method->value) }}
                                </option>
                            @endforeach
                        </x-ui.select>
                    </div>

                    <div>
                        <x-ui.input name="reference" label="{{ __('payments.reference') }}" maxlength="100" />
                    </div>

                    <div>
                        <x-ui.textarea name="notes" label="{{ __('payments.notes') }}" rows="2" maxlength="2000" />
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
                        <button
                            type="button"
                            x-on:click="close"
                            class="inline-flex shrink-0 items-center justify-center rounded-[7px] border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition-colors duration-150 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                        >
                            {{ __('payments.record_cancel') }}
                        </button>
                        <x-ui.button type="submit" icon="banknotes">{{ __('payments.record_submit') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.modal>
        @endif
    @endcan

    @can('payments.reverse')
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
                                    class="block w-full rounded-[7px] border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm
                                        placeholder:text-slate-400 transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30
                                        dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500"
                                ></textarea>
                            </div>

                            <div class="flex flex-wrap items-center justify-end gap-3">
                                <button
                                    type="button"
                                    x-on:click="close"
                                    class="inline-flex shrink-0 items-center justify-center rounded-[7px] border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition-colors duration-150 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
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
    @endcan
@endsection