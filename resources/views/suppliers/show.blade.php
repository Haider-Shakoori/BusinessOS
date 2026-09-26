@extends('layouts.app')

@section('content')
<x-app.page icon="truck" :title="$supplier->name" :subtitle="$supplier->code ?: __('suppliers.details')">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="{{ route('suppliers.ledger', $supplier) }}" variant="secondary">{{ __('suppliers.ledger_link') }}</x-ui.button>
            <x-ui.button href="{{ route('suppliers.statement', $supplier) }}" variant="secondary" icon="document-text">{{ __('suppliers.statement_link') }}</x-ui.button>
            @can('purchasing.manage')
                <x-ui.button href="{{ route('suppliers.edit', $supplier) }}" variant="secondary" icon="pencil">{{ __('actions.edit') }}</x-ui.button>
            @endcan
        </div>
    </x-slot:actions>

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-ui.stat-card :title="__('suppliers.summary.outstanding')" :value="$summary['outstanding_balance']" icon="banknotes" tone="warning" />
        <x-ui.stat-card :title="__('suppliers.summary.purchases')" :value="$summary['total_purchases']" icon="shopping-cart" tone="info" />
        <x-ui.stat-card :title="__('suppliers.summary.returns')" :value="$summary['total_returns']" icon="arrow-uturn-left" tone="neutral" />
        <x-ui.stat-card :title="__('suppliers.summary.paid')" :value="$summary['total_paid']" icon="check-circle" tone="success" />
        <x-ui.stat-card :title="__('suppliers.summary.opening')" :value="$summary['opening_balance']" icon="clock" tone="brand" />
    </div>
    <p class="mt-2 text-xs text-slate-500">{{ $baseCurrency }}</p>

    <div class="mt-5 grid gap-5 xl:grid-cols-3">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('suppliers.details') }}</h2></x-slot:header>
            <dl class="space-y-3 text-sm">
                <div><dt class="text-slate-500">{{ __('suppliers.fields.email') }}</dt><dd class="font-medium text-slate-900 dark:text-white">{{ $supplier->email ?: '—' }}</dd></div>
                <div><dt class="text-slate-500">{{ __('suppliers.fields.phone') }}</dt><dd class="font-medium text-slate-900 dark:text-white">{{ $supplier->phone ?: '—' }}</dd></div>
                <div><dt class="text-slate-500">{{ __('suppliers.fields.address') }}</dt><dd class="font-medium text-slate-900 dark:text-white">{{ $supplier->address ?: '—' }}</dd></div>
                <div><dt class="text-slate-500">{{ __('suppliers.fields.notes') }}</dt><dd class="font-medium text-slate-900 dark:text-white">{{ $supplier->notes ?: '—' }}</dd></div>
            </dl>
        </x-ui.card>

        @can('purchasing.manage')
        <x-ui.card class="xl:col-span-2">
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('suppliers.record_payment') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('suppliers.payments.store', $supplier) }}" class="grid gap-4 sm:grid-cols-3">
                @csrf
                <x-ui.select name="purchase_order_id" :label="__('suppliers.fields.purchase_order')">
                    <option value="">—</option>
                    @foreach($payablePurchases as $purchase)
                        <option value="{{ $purchase->id }}">{{ $purchase->number }} — {{ $purchase->total }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="payment_date" type="date" :label="__('suppliers.fields.payment_date')" :value="now()->toDateString()" required />
                <x-ui.input name="amount" type="number" step="0.0001" min="0.0001" :label="__('suppliers.fields.amount')" required />
                <x-ui.select name="payment_method" :label="__('suppliers.fields.payment_method')" required>
                    @foreach(['cash','bank_transfer','card','mobile','cheque','other'] as $method)
                        <option value="{{ $method }}">{{ __('suppliers.payment_methods.'.$method) }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="reference" :label="__('suppliers.fields.reference')" />
                <x-ui.input name="notes" :label="__('suppliers.fields.notes')" />
                <div class="sm:col-span-3 flex justify-end"><x-ui.button type="submit" icon="plus">{{ __('suppliers.record_payment') }}</x-ui.button></div>
            </form>
        </x-ui.card>
        @endcan
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.purchasing.purchase_orders') }}</h2></x-slot:header>
            <div class="space-y-3">
                @forelse($purchases as $purchase)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <div><div class="font-medium text-slate-900 dark:text-white">{{ $purchase->number }}</div><div class="text-xs text-slate-500">{{ $purchase->order_date?->format('Y-m-d') }}</div></div>
                        <div class="text-end"><div class="font-semibold">{{ $purchase->total }}</div><div class="text-xs text-slate-500">{{ ucfirst($purchase->status) }}</div></div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('suppliers.no_purchases') }}</p>
                @endforelse
            </div>
        </x-ui.card>

        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('suppliers.summary.paid') }}</h2></x-slot:header>
            <div class="space-y-3">
                @forelse($payments as $payment)
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="font-medium text-slate-900 dark:text-white">{{ $payment->payment_number }}</div>
                                <div class="text-xs text-slate-500">{{ $payment->payment_date?->format('Y-m-d') }} · {{ __('suppliers.payment_methods.'.$payment->payment_method) }}</div>
                            </div>
                            <div class="text-end">
                                <div class="font-semibold">{{ $payment->amount }} {{ $payment->currency_code }}</div>
                                @if($payment->reversed_at)<x-ui.badge tone="danger">{{ __('suppliers.ledger.reversal') }}</x-ui.badge>@endif
                            </div>
                        </div>
                        @can('purchasing.manage')
                            @if(!$payment->reversed_at)
                                <form method="POST" action="{{ route('suppliers.payments.reverse', [$supplier, $payment]) }}" class="mt-3 flex gap-2">
                                    @csrf
                                    <input name="reversal_reason" class="min-w-0 flex-1 rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900" placeholder="{{ __('suppliers.fields.notes') }}">
                                    <x-ui.button type="submit" size="sm" variant="danger">{{ __('suppliers.reverse_payment') }}</x-ui.button>
                                </form>
                            @endif
                        @endcan
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('suppliers.no_payments') }}</p>
                @endforelse
            </div>
        </x-ui.card>
    </div>
</x-app.page>
@endsection
