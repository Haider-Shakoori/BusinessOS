@extends('layouts.app')

@section('content')
<x-app.page
    icon="users"
    :title="$supplier->name"
    :subtitle="$supplier->code"
    :breadcrumbs="[
        ['label' => __('modules.dashboard'), 'url' => route('app.home')],
        ['label' => __('suppliers.title'), 'url' => route('suppliers.index')],
        ['label' => $supplier->name],
    ]"
>
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button variant="secondary" href="{{ route('suppliers.ledger', $supplier) }}" icon="ledger">{{ __('suppliers.ledger') }}</x-ui.button>
            <x-ui.button variant="secondary" href="{{ route('suppliers.statement', $supplier) }}" icon="document-text">{{ __('suppliers.statement') }}</x-ui.button>
            @can('suppliers.manage')
                <x-ui.button href="{{ route('suppliers.edit', $supplier) }}" icon="pencil-square">{{ __('suppliers.edit') }}</x-ui.button>
            @endcan
        </div>
    </x-slot:actions>

    @if(session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif
    @if($errors->any())
        <div class="mb-5"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-ui.stat-card :title="__('suppliers.opening_balance')" :value="$baseCurrency.' '.number_format((float)$summary['opening_balance'], 4)" icon="banknotes" tone="neutral" />
        <x-ui.stat-card :title="__('suppliers.total_purchased')" :value="$baseCurrency.' '.number_format((float)$summary['total_purchased'], 4)" icon="shopping-cart" tone="brand" />
        <x-ui.stat-card :title="__('suppliers.total_returned')" :value="$baseCurrency.' '.number_format((float)$summary['total_returned'], 4)" icon="arrow-uturn-left" tone="warning" />
        <x-ui.stat-card :title="__('suppliers.total_paid')" :value="$baseCurrency.' '.number_format((float)$summary['total_paid'], 4)" icon="banknotes" tone="success" />
        <x-ui.stat-card :title="__('suppliers.outstanding')" :value="$baseCurrency.' '.number_format((float)$summary['outstanding_balance'], 4)" icon="ledger" tone="danger" />
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-3">
        <x-ui.card class="xl:col-span-1">
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('suppliers.details') }}</h2></x-slot:header>
            <dl class="space-y-4 text-sm">
                <div><dt class="text-xs font-semibold uppercase text-slate-500">{{ __('suppliers.code') }}</dt><dd class="mt-1 font-medium text-slate-900 dark:text-white" dir="ltr">{{ $supplier->code }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase text-slate-500">{{ __('suppliers.email') }}</dt><dd class="mt-1 text-slate-700 dark:text-slate-200" dir="ltr">{{ $supplier->email ?: '—' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase text-slate-500">{{ __('suppliers.phone') }}</dt><dd class="mt-1 text-slate-700 dark:text-slate-200" dir="ltr">{{ $supplier->phone ?: '—' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase text-slate-500">{{ __('suppliers.address') }}</dt><dd class="mt-1 whitespace-pre-line text-slate-700 dark:text-slate-200">{{ $supplier->address ?: '—' }}</dd></div>
                <div><dt class="text-xs font-semibold uppercase text-slate-500">{{ __('suppliers.notes') }}</dt><dd class="mt-1 whitespace-pre-line text-slate-700 dark:text-slate-200">{{ $supplier->notes ?: '—' }}</dd></div>
            </dl>

            @can('suppliers.manage')
                <form method="POST" action="{{ route('suppliers.destroy', $supplier) }}" class="mt-6 border-t border-slate-200 pt-5 dark:border-slate-700" onsubmit="return confirm('{{ __('suppliers.delete_confirm') }}')">
                    @csrf
                    @method('DELETE')
                    <x-ui.button type="submit" variant="danger" size="sm" icon="trash">{{ __('suppliers.delete') }}</x-ui.button>
                </form>
            @endcan
        </x-ui.card>

        <div class="space-y-5 xl:col-span-2">
            @can('suppliers.manage')
                @can('payments.create')
                    <x-ui.card>
                        <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('suppliers.record_payment') }}</h2></x-slot:header>
                        <form method="POST" action="{{ route('suppliers.payments.store', $supplier) }}" class="grid gap-4 md:grid-cols-3">
                            @csrf
                            <x-ui.input name="amount" type="number" step="0.0001" min="0.0001" :label="__('suppliers.payment_amount')" required />
                            <x-ui.select name="payment_method" :label="__('suppliers.payment_method')" required>
                                @foreach($paymentMethods as $method)
                                    <option value="{{ $method->value }}">{{ __('payments.methods.'.$method->value) }}</option>
                                @endforeach
                            </x-ui.select>
                            <x-ui.input name="payment_date" type="date" :value="now()->toDateString()" :label="__('suppliers.payment_date')" required />
                            <x-ui.input name="reference" :label="__('suppliers.reference')" maxlength="100" />
                            <div class="md:col-span-2"><x-ui.input name="notes" :label="__('suppliers.payment_notes')" maxlength="2000" /></div>
                            <div class="md:col-span-3 flex justify-end"><x-ui.button type="submit" icon="banknotes">{{ __('suppliers.record_payment') }}</x-ui.button></div>
                        </form>
                    </x-ui.card>
                @endcan
            @endcan

            <x-ui.card>
                <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('suppliers.recent_purchases') }}</h2></x-slot:header>
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <x-slot:head><tr><x-ui.th>{{ __('operations.purchasing.number') }}</x-ui.th><x-ui.th>{{ __('operations.purchasing.order_date') }}</x-ui.th><x-ui.th>{{ __('operations.purchasing.status') }}</x-ui.th><x-ui.th numeric>{{ __('operations.purchasing.total') }}</x-ui.th></tr></x-slot:head>
                        @forelse($supplier->purchaseOrders as $order)
                            <tr><x-ui.td>{{ $order->number }}</x-ui.td><x-ui.td>{{ $order->order_date?->format('Y-m-d') }}</x-ui.td><x-ui.td>{{ ucfirst($order->status) }}</x-ui.td><x-ui.td numeric>{{ $baseCurrency }} {{ number_format((float)$order->total, 4) }}</x-ui.td></tr>
                        @empty
                            <tr><x-ui.td colspan="4">{{ __('suppliers.no_purchases') }}</x-ui.td></tr>
                        @endforelse
                    </x-ui.table>
                </div>
            </x-ui.card>

            <x-ui.card>
                <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('suppliers.recent_payments') }}</h2></x-slot:header>
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <x-slot:head><tr><x-ui.th>{{ __('payments.number') }}</x-ui.th><x-ui.th>{{ __('payments.date') }}</x-ui.th><x-ui.th>{{ __('payments.method') }}</x-ui.th><x-ui.th>{{ __('payments.status') }}</x-ui.th><x-ui.th numeric>{{ __('payments.amount') }}</x-ui.th><x-ui.th></x-ui.th></tr></x-slot:head>
                        @forelse($supplier->payments as $payment)
                            <tr>
                                <x-ui.td>{{ $payment->payment_number }}</x-ui.td>
                                <x-ui.td>{{ $payment->payment_date?->format('Y-m-d') }}</x-ui.td>
                                <x-ui.td>{{ __('payments.methods.'.$payment->payment_method) }}</x-ui.td>
                                <x-ui.td><x-ui.status-badge :status="$payment->reversed_at ? 'reversed' : 'active'" :label="__('payments.statuses.'.($payment->reversed_at ? 'reversed' : 'active'))" /></x-ui.td>
                                <x-ui.td numeric>{{ $payment->currency_code }} {{ number_format((float)$payment->amount, 4) }}</x-ui.td>
                                <x-ui.td>
                                    @if(!$payment->reversed_at)
                                        @can('payments.reverse')
                                            <form method="POST" action="{{ route('suppliers.payments.reverse', [$supplier, $payment]) }}" class="flex items-center gap-2">
                                                @csrf
                                                <input name="reversal_reason" maxlength="1000" class="w-36 rounded-md border-slate-300 text-xs dark:border-slate-600 dark:bg-slate-900" placeholder="{{ __('suppliers.reversal_reason') }}">
                                                <x-ui.button type="submit" size="sm" variant="danger">{{ __('suppliers.reverse_payment') }}</x-ui.button>
                                            </form>
                                        @endcan
                                    @endif
                                </x-ui.td>
                            </tr>
                        @empty
                            <tr><x-ui.td colspan="6">{{ __('suppliers.no_payments') }}</x-ui.td></tr>
                        @endforelse
                    </x-ui.table>
                </div>
            </x-ui.card>
        </div>
    </div>
</x-app.page>
@endsection
