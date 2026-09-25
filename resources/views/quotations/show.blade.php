@extends('layouts.app')

@section('content')
    <x-app.page icon="document-text"
        :title="$quotation->quotation_number"
        :subtitle="__('quotations.document')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('quotations.title'), 'url' => route('quotations.index')],
            ['label' => $quotation->quotation_number],
        ]"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('quotations.index') }}" icon="arrow-right">
                {{ __('quotations.view_all') }}
            </x-ui.button>

            <button
                type="button"
                x-on:click="window.print()"
                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-[7px] border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition-colors duration-150 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
            >
                <x-ui.icon name="document-text" class="size-4" />
                {{ __('quotations.print') }}
            </button>

            @can('quotations.manage')
                @if ($editable)
                    <x-ui.button href="{{ route('quotations.edit', $quotation) }}" icon="pencil-square">
                        {{ __('actions.edit') }}
                    </x-ui.button>
                    <x-ui.button
                        variant="danger"
                        icon="trash"
                        x-on:click="$dispatch('bos:open-modal', { id: 'delete-quotation-modal' })"
                    >
                        {{ __('actions.delete') }}
                    </x-ui.button>
                @endif
            @endcan

            @can('invoices.manage')
                @if ($quotation->status !== \App\Enums\QuotationStatus::Converted)
                    <x-ui.button
                        icon="receipt-percent"
                        x-on:click="$dispatch('bos:open-modal', { id: 'convert-quotation-modal' })"
                    >
                        {{ __('invoices.convert') }}
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
                <x-ui.alert type="info">{{ __('quotations.read_only') }}</x-ui.alert>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('quotations.line_items') }}</h2>
                            <x-ui.status-badge :status="$quotation->status->value" :label="__('quotations.statuses.'.$quotation->status->value)" />
                        </div>
                    </x-slot:header>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
                            <thead>
                                <tr>
                                    <x-ui.th>{{ __('quotations.product') }}</x-ui.th>
                                    <x-ui.th>{{ __('quotations.description') }}</x-ui.th>
                                    <x-ui.th class="text-end">{{ __('quotations.quantity') }}</x-ui.th>
                                    <x-ui.th class="text-end">{{ __('quotations.unit_price') }}</x-ui.th>
                                    @if ($quotation->tax_amount > 0)
                                        <x-ui.th class="text-end">{{ __('quotations.tax') }}</x-ui.th>
                                    @endif
                                    <x-ui.th class="text-end">{{ __('quotations.amount') }}</x-ui.th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($quotation->items as $item)
                                    <tr>
                                        <x-ui.td>
                                            <span class="text-slate-500 dark:text-slate-400">{{ $item->product?->name ?: __('quotations.no_product') }}</span>
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
                                        @if ($quotation->tax_amount > 0)
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
                                <dt class="text-slate-500 dark:text-slate-400">{{ __('quotations.subtotal') }}</dt>
                                <dd class="whitespace-nowrap tabular-nums text-slate-900 dark:text-slate-100" dir="ltr">{{ $quotation->subtotal }}</dd>
                            </div>
                            @if ($quotation->discount_type && $quotation->discount_amount > 0)
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-slate-500 dark:text-slate-400">
                                        {{ __('quotations.discount') }}
                                        @if ($quotation->discount_type === 'percentage')
                                            ({{ $quotation->discount_amount }}%)
                                        @endif
                                    </dt>
                                    <dd class="whitespace-nowrap tabular-nums text-slate-900 dark:text-slate-100" dir="ltr">−{{ $quotation->discount_amount }}</dd>
                                </div>
                            @endif
                            @if ($quotation->tax_amount > 0)
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-slate-500 dark:text-slate-400">{{ __('quotations.tax_amount') }}</dt>
                                    <dd class="whitespace-nowrap tabular-nums text-slate-900 dark:text-slate-100" dir="ltr">{{ $quotation->tax_amount }}</dd>
                                </div>
                            @endif
                            <div class="flex items-center justify-between gap-4 border-t border-slate-200 pt-2 dark:border-slate-700">
                                <dt class="text-base font-semibold text-slate-900 dark:text-white">{{ __('quotations.total') }}</dt>
                                <dd class="whitespace-nowrap text-base font-semibold tabular-nums text-slate-900 dark:text-white" dir="ltr">{{ $quotation->total }}</dd>
                            </div>
                            @if ($quotation->currency_code !== $baseCurrency)
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-xs text-slate-500 dark:text-slate-400">{{ __('currencies.base_amount', ['currency' => $baseCurrency]) }}</dt>
                                    <dd class="whitespace-nowrap text-xs tabular-nums text-slate-500 dark:text-slate-400" dir="ltr">{{ $quotation->base_amount }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>
                </x-ui.card>

                @if ($quotation->notes || $quotation->terms)
                    <div class="mt-6 grid gap-6 sm:grid-cols-2">
                        @if ($quotation->notes)
                            <x-ui.card>
                                <x-slot:header>
                                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('quotations.notes') }}</h2>
                                </x-slot:header>
                                <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $quotation->notes }}</p>
                            </x-ui.card>
                        @endif
                        @if ($quotation->terms)
                            <x-ui.card>
                                <x-slot:header>
                                    <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('quotations.terms') }}</h2>
                                </x-slot:header>
                                <p class="whitespace-pre-line text-sm text-slate-700 dark:text-slate-300">{{ $quotation->terms }}</p>
                            </x-ui.card>
                        @endif
                    </div>
                @endif
            </div>

            <div class="lg:col-span-1">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('quotations.information') }}</h2>
                    </x-slot:header>

                    <dl class="space-y-4">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('quotations.number') }}</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white" dir="ltr">{{ $quotation->quotation_number }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('quotations.customer') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">
                                @if ($quotation->customer)
                                    <a href="{{ route('customers.show', $quotation->customer) }}" class="text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300">
                                        {{ $quotation->customer->name }}
                                    </a>
                                @else
                                    {{ __('quotations.no_customer') }}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('quotations.date') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $quotation->date->format('Y-m-d') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('currencies.currency') }}</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900 dark:text-white" dir="ltr">{{ $quotation->currency_code }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('quotations.expiry_date') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $quotation->expiry_date?->format('Y-m-d') ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('quotations.status') }}</dt>
                            <dd class="mt-1">
                                <x-ui.status-badge :status="$quotation->status->value" :label="__('quotations.statuses.'.$quotation->status->value)" />
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('quotations.created_by') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $quotation->createdBy?->name ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('quotations.created_at') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $quotation->created_at?->format('Y-m-d H:i') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('quotations.updated_at') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $quotation->updated_at?->format('Y-m-d H:i') }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            </div>
        </div>
    </x-app.page>

    @can('quotations.manage')
        @if ($editable)
            <x-ui.modal id="delete-quotation-modal" :title="__('quotations.delete_confirm_title', ['name' => $quotation->quotation_number])" size="sm">
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ __('quotations.delete_confirm', ['name' => $quotation->quotation_number]) }}
                </p>

                <form method="POST" action="{{ route('quotations.destroy', $quotation) }}" class="mt-6 flex flex-wrap items-center justify-end gap-3">
                    @csrf
                    @method('DELETE')
                    <button
                        type="button"
                        x-on:click="close"
                        class="inline-flex shrink-0 items-center justify-center rounded-[7px] border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition-colors duration-150 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                    >
                        {{ __('quotations.delete_cancel') }}
                    </button>
                    <x-ui.button type="submit" variant="danger" icon="trash">{{ __('quotations.delete_submit') }}</x-ui.button>
                </form>
            </x-ui.modal>
        @endif
    @endcan

    @can('invoices.manage')
        @if ($quotation->status !== \App\Enums\QuotationStatus::Converted)
            <x-ui.modal id="convert-quotation-modal" :title="__('invoices.convert_confirm_title', ['name' => $quotation->quotation_number])" size="sm">
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ __('invoices.convert_confirm', ['name' => $quotation->quotation_number]) }}
                </p>

                <form method="POST" action="{{ route('quotations.convert', $quotation) }}" class="mt-6 flex flex-wrap items-center justify-end gap-3">
                    @csrf
                    <button
                        type="button"
                        x-on:click="close"
                        class="inline-flex shrink-0 items-center justify-center rounded-[7px] border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition-colors duration-150 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                    >
                        {{ __('actions.cancel') }}
                    </button>
                    <x-ui.button type="submit" icon="receipt-percent">{{ __('invoices.convert') }}</x-ui.button>
                </form>
            </x-ui.modal>
        @endif
    @endcan
@endsection