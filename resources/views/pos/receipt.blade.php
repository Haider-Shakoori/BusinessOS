@extends('layouts.pos')

@section('content')
<div class="min-h-screen p-4 sm:p-6">
    <div class="no-print mx-auto mb-4 flex max-w-xl flex-wrap items-center justify-between gap-3">
        <a href="{{ route('pos.index', ['register' => $sale->pos_register_id]) }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
            <x-ui.icon name="arrow-uturn-left" class="size-4" />
            {{ __('pos.new_sale') }}
        </a>
        <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
            <x-ui.icon name="receipt-list" class="size-4" />
            {{ __('pos.print_receipt') }}
        </button>
    </div>

    @if(session('status'))
        <div class="no-print mx-auto mb-4 max-w-xl"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif
    @if($errors->any())
        <div class="no-print mx-auto mb-4 max-w-xl"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div>
    @endif

    <article class="print-shell mx-auto max-w-xl rounded-2xl border border-slate-200 bg-white p-6 shadow-xl dark:border-slate-800 dark:bg-slate-900">
        <div class="text-center">
            <h1 class="text-xl font-black text-slate-950 dark:text-white">{{ config('app.name') }}</h1>
            <p class="mt-1 text-sm font-semibold text-slate-500">{{ __('pos.receipt') }}</p>
            <div class="mt-3 inline-flex rounded-full px-3 py-1 text-xs font-bold {{ $sale->status === 'voided' ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700' }}">
                {{ __('pos.'.$sale->status) }}
            </div>
        </div>

        <div class="mt-6 grid grid-cols-2 gap-x-6 gap-y-2 border-y border-dashed border-slate-300 py-4 text-sm dark:border-slate-700">
            <div><span class="text-slate-500">{{ __('pos.receipt_number') }}:</span> <strong>{{ $sale->sale_number }}</strong></div>
            <div class="text-end"><span class="text-slate-500">{{ __('pos.date') }}:</span> <strong>{{ $sale->completed_at?->format('Y-m-d H:i') }}</strong></div>
            <div><span class="text-slate-500">{{ __('pos.register') }}:</span> <strong>{{ $sale->register?->name }}</strong></div>
            <div class="text-end"><span class="text-slate-500">{{ __('pos.cashier') }}:</span> <strong>{{ $sale->cashier?->name }}</strong></div>
            @if($sale->customer)
                <div class="col-span-2"><span class="text-slate-500">{{ __('pos.customer') }}:</span> <strong>{{ $sale->customer->name }}</strong></div>
            @endif
        </div>

        <div class="mt-5 space-y-3">
            @foreach($sale->items as $item)
                <div class="flex items-start justify-between gap-4 text-sm">
                    <div class="min-w-0">
                        <div class="font-semibold text-slate-900 dark:text-white">{{ $item->product_name }}</div>
                        <div class="text-xs text-slate-500">{{ $item->quantity }} × {{ number_format((float) $item->unit_price, 2) }} @if((float)$item->tax_rate > 0) · {{ $item->tax_rate }}% {{ __('pos.tax') }} @endif</div>
                    </div>
                    <div class="shrink-0 font-semibold">{{ number_format((float) $item->line_total, 2) }}</div>
                </div>
            @endforeach
        </div>

        <div class="mt-5 space-y-2 border-t border-dashed border-slate-300 pt-4 text-sm dark:border-slate-700">
            <div class="flex justify-between"><span class="text-slate-500">{{ __('pos.subtotal') }}</span><strong>{{ number_format((float) $sale->subtotal, 2) }}</strong></div>
            @if((float)$sale->discount_amount > 0)
                <div class="flex justify-between"><span class="text-slate-500">{{ __('pos.discount') }}</span><strong>-{{ number_format((float) $sale->discount_amount, 2) }}</strong></div>
            @endif
            @if((float)$sale->tax_amount > 0)
                <div class="flex justify-between"><span class="text-slate-500">{{ __('pos.tax') }}</span><strong>{{ number_format((float) $sale->tax_amount, 2) }}</strong></div>
            @endif
            <div class="flex justify-between border-t border-slate-200 pt-3 text-lg dark:border-slate-700"><span class="font-black">{{ __('pos.total') }}</span><strong>{{ number_format((float) $sale->total, 2) }} {{ $sale->currency_code }}</strong></div>
            <div class="flex justify-between"><span class="text-slate-500">{{ __('pos.payment_method') }}</span><strong>{{ __('pos.'.$sale->payment_method) }}</strong></div>
            @if($sale->payment_method === 'cash')
                <div class="flex justify-between"><span class="text-slate-500">{{ __('pos.amount_tendered') }}</span><strong>{{ number_format((float) $sale->amount_tendered, 2) }}</strong></div>
                <div class="flex justify-between"><span class="text-slate-500">{{ __('pos.change_due') }}</span><strong>{{ number_format((float) $sale->change_due, 2) }}</strong></div>
            @endif
        </div>

        @if($sale->status === 'voided')
            <div class="mt-5 rounded-lg bg-rose-50 p-3 text-sm text-rose-800">
                <strong>{{ __('pos.voided') }}:</strong> {{ $sale->void_reason }}
            </div>
        @endif

        <p class="mt-6 text-center text-xs text-slate-400">{{ __('pos.cost_accounting_note') }}</p>
    </article>

    @can('pos.manage')
        @if($sale->status !== 'voided')
            <div class="no-print mx-auto mt-4 max-w-xl">
                <details class="rounded-xl border border-rose-200 bg-white p-4 dark:border-rose-900/50 dark:bg-slate-900">
                    <summary class="cursor-pointer text-sm font-semibold text-rose-700">{{ __('pos.void_sale') }}</summary>
                    <form method="POST" action="{{ route('pos.sales.void', $sale) }}" class="mt-3 space-y-3" onsubmit="return confirm('{{ __('pos.void_confirm') }}')">
                        @csrf
                        <x-ui.input name="reason" :label="__('pos.void_reason')" required />
                        <x-ui.button type="submit" variant="danger" class="w-full justify-center">{{ __('pos.void_sale') }}</x-ui.button>
                    </form>
                </details>
            </div>
        @endif
    @endcan
</div>
@endsection
