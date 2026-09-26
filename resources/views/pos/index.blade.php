@extends('layouts.pos')

@section('content')
@php
    $settings = app(App\Services\BusinessSettings::class);
    $currency = (string) $settings->get('regional.currency', 'AFN');
    $taxEnabled = (bool) $settings->get('general.tax_enabled', false);
    $productPayload = $products->map(function ($product) use ($stock, $taxEnabled) {
        return [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'price' => (float) $product->sale_price,
            'type' => $product->type->value,
            'stock' => $product->type->value === 'service' ? null : (float) ($stock[$product->id] ?? 0),
            'tax_rate' => $taxEnabled && $product->tax ? (float) $product->tax->rate : 0,
            'category' => $product->category?->name,
        ];
    })->values();
@endphp

<div class="min-h-screen">
    <header class="no-print sticky top-0 z-40 border-b border-slate-200 bg-white/95 px-4 py-3 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95">
        <div class="mx-auto flex max-w-[1800px] flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <a href="{{ route('app.home') }}" class="inline-flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                    <x-ui.icon name="arrow-uturn-left" class="size-4" />
                    {{ __('pos.back_to_erp') }}
                </a>
                <div>
                    <h1 class="text-lg font-bold text-slate-950 dark:text-white">{{ __('pos.title') }}</h1>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('pos.subtitle') }}</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if($registers->isNotEmpty())
                    <form method="GET" action="{{ route('pos.index') }}">
                        <select name="register" onchange="this.form.submit()" class="rounded-lg border-slate-300 bg-white py-2 ps-3 pe-8 text-sm font-medium dark:border-slate-700 dark:bg-slate-900">
                            @foreach($registers as $item)
                                <option value="{{ $item->id }}" @selected($register?->id === $item->id)>
                                    {{ $item->name }} · {{ $item->warehouse?->name }}
                                </option>
                            @endforeach
                        </select>
                    </form>
                @endif

                @if($openShift)
                    <span class="rounded-full bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                        {{ __('pos.cashier') }}: {{ auth()->user()->name }}
                    </span>
                @endif
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-[1800px] p-4 lg:p-5">
        @if(session('status'))
            <div class="mb-4"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
        @endif
        @if($errors->any())
            <div class="mb-4">
                <x-ui.alert type="danger">
                    <ul class="list-disc space-y-1 ps-5">
                        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                    </ul>
                </x-ui.alert>
            </div>
        @endif

        @if($registers->isEmpty())
            <div class="mx-auto mt-10 max-w-2xl">
                <x-ui.card>
                    <div class="text-center">
                        <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                            <x-ui.icon name="building-storefront" class="size-7" />
                        </div>
                        <h2 class="mt-4 text-xl font-bold text-slate-950 dark:text-white">{{ __('pos.setup_title') }}</h2>
                        <p class="mx-auto mt-2 max-w-xl text-sm text-slate-500 dark:text-slate-400">{{ __('pos.setup_help') }}</p>
                    </div>
                    @can('pos.manage')
                        <form method="POST" action="{{ route('pos.registers.store') }}" class="mt-6 grid gap-4 sm:grid-cols-2">
                            @csrf
                            <x-ui.input name="code" :label="__('pos.register_code')" placeholder="POS-01" required />
                            <x-ui.input name="name" :label="__('pos.register_name')" placeholder="Main Counter" required />
                            <div class="sm:col-span-2">
                                @if($warehouses->isNotEmpty())
                                    <x-ui.select name="warehouse_id" :label="__('pos.warehouse')" required>
                                        @foreach($warehouses as $warehouse)
                                            <option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>
                                        @endforeach
                                    </x-ui.select>
                                @else
                                    <div class="rounded-lg bg-brand-50 p-3 text-sm text-brand-800 dark:bg-brand-500/10 dark:text-brand-200">{{ __('pos.warehouse_auto') }}</div>
                                @endif
                            </div>
                            <div class="sm:col-span-2 flex justify-end">
                                <x-ui.button type="submit" icon="plus">{{ __('pos.create_register') }}</x-ui.button>
                            </div>
                        </form>
                    @endcan
                </x-ui.card>
            </div>
        @elseif(!$openShift)
            <div class="mx-auto grid max-w-5xl gap-5 lg:grid-cols-5">
                <div class="lg:col-span-3">
                    <x-ui.card>
                        @if($registerOpenShift)
                            <div class="text-center">
                                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                                    <x-ui.icon name="clock" class="size-7" />
                                </div>
                                <h2 class="mt-4 text-xl font-bold text-slate-950 dark:text-white">{{ __('pos.occupied_title') }}</h2>
                                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('pos.occupied_help', ['user' => $registerOpenShift->user?->name]) }}</p>
                            </div>
                        @else
                            <div class="text-center">
                                <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
                                    <x-ui.icon name="building-storefront" class="size-7" />
                                </div>
                                <h2 class="mt-4 text-xl font-bold text-slate-950 dark:text-white">{{ __('pos.open_shift') }}</h2>
                                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $register->name }} · {{ $register->warehouse?->name }}</p>
                            </div>
                            @can('pos.sell')
                                <form method="POST" action="{{ route('pos.shifts.open', $register) }}" class="mx-auto mt-6 max-w-sm">
                                    @csrf
                                    <x-ui.input name="opening_cash" type="number" min="0" step="0.0001" :label="__('pos.opening_cash')" value="0" required />
                                    <div class="mt-4">
                                        <x-ui.button type="submit" class="w-full justify-center" icon="bolt">{{ __('pos.open_shift') }}</x-ui.button>
                                    </div>
                                </form>
                            @endcan
                        @endif
                    </x-ui.card>
                </div>

                @can('pos.manage')
                    <div class="lg:col-span-2">
                        <x-ui.card>
                            <x-slot:header><h3 class="font-semibold text-slate-900 dark:text-white">{{ __('pos.create_register') }}</h3></x-slot:header>
                            <form method="POST" action="{{ route('pos.registers.store') }}" class="space-y-4">
                                @csrf
                                <x-ui.input name="code" :label="__('pos.register_code')" required />
                                <x-ui.input name="name" :label="__('pos.register_name')" required />
                                @if($warehouses->isNotEmpty())
                                    <x-ui.select name="warehouse_id" :label="__('pos.warehouse')" required>
                                        @foreach($warehouses as $warehouse)
                                            <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                                        @endforeach
                                    </x-ui.select>
                                @else
                                    <div class="rounded-lg bg-brand-50 p-3 text-sm text-brand-800 dark:bg-brand-500/10 dark:text-brand-200">{{ __('pos.warehouse_auto') }}</div>
                                @endif
                                <x-ui.button type="submit" variant="secondary" class="w-full justify-center">{{ __('pos.create_register') }}</x-ui.button>
                            </form>
                        </x-ui.card>
                    </div>
                @endcan
            </div>
        @else
            <div
                x-data="{
                    products: @js($productPayload),
                    cart: [],
                    query: '',
                    payment: 'cash',
                    customer: '',
                    discount: 0,
                    tendered: 0,
                    currency: @js($currency),
                    get filtered() {
                        const q = this.query.trim().toLowerCase();
                        if (!q) return this.products;
                        return this.products.filter(p =>
                            p.name.toLowerCase().includes(q)
                            || (p.sku || '').toLowerCase().includes(q)
                            || (p.category || '').toLowerCase().includes(q)
                        );
                    },
                    cartQty(id) {
                        return this.cart.find(i => i.id === id)?.quantity || 0;
                    },
                    canAdd(product) {
                        return product.type === 'service' || this.cartQty(product.id) < product.stock;
                    },
                    add(product) {
                        if (!this.canAdd(product)) return;
                        const found = this.cart.find(i => i.id === product.id);
                        if (found) found.quantity = +(found.quantity + 1).toFixed(4);
                        else this.cart.push({...product, quantity: 1});
                    },
                    decrease(item) {
                        item.quantity = +(item.quantity - 1).toFixed(4);
                        if (item.quantity <= 0) this.remove(item.id);
                    },
                    remove(id) {
                        this.cart = this.cart.filter(i => i.id !== id);
                    },
                    lineSubtotal(item) {
                        return item.price * item.quantity;
                    },
                    lineTax(item) {
                        return this.lineSubtotal(item) * item.tax_rate / 100;
                    },
                    get subtotal() {
                        return this.cart.reduce((sum, i) => sum + this.lineSubtotal(i), 0);
                    },
                    get tax() {
                        return this.cart.reduce((sum, i) => sum + this.lineTax(i), 0);
                    },
                    get total() {
                        return Math.max(0, this.subtotal - (+this.discount || 0) + this.tax);
                    },
                    get change() {
                        return this.payment === 'cash' ? Math.max(0, (+this.tendered || 0) - this.total) : 0;
                    },
                    money(value) {
                        return Number(value || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + this.currency;
                    },
                    payload() {
                        return JSON.stringify(this.cart.map(i => ({product_id: i.id, quantity: i.quantity})));
                    }
                }"
                class="grid min-h-[calc(100vh-105px)] gap-4 xl:grid-cols-[minmax(0,1fr)_440px]"
            >
                <section class="flex min-h-0 flex-col rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="border-b border-slate-100 p-4 dark:border-slate-800">
                        <div class="relative">
                            <x-ui.icon name="search" class="pointer-events-none absolute start-3 top-1/2 size-5 -translate-y-1/2 text-slate-400" />
                            <input x-model="query" type="search" placeholder="{{ __('pos.search_products') }}" class="w-full rounded-xl border-slate-300 bg-slate-50 py-3 ps-11 pe-4 text-sm focus:border-brand-500 focus:ring-brand-500 dark:border-slate-700 dark:bg-slate-950">
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto p-4">
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                            <template x-for="product in filtered" :key="product.id">
                                <button
                                    type="button"
                                    @click="add(product)"
                                    :disabled="!canAdd(product)"
                                    class="group min-h-32 rounded-xl border border-slate-200 bg-white p-4 text-start transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-md disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-700 dark:bg-slate-900 dark:hover:border-brand-600"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="flex size-10 items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                                            <x-ui.icon name="cube" class="size-5" />
                                        </div>
                                        <span class="text-xs font-semibold text-slate-500" x-text="product.sku || ''"></span>
                                    </div>
                                    <div class="mt-3 line-clamp-2 text-sm font-semibold text-slate-900 dark:text-white" x-text="product.name"></div>
                                    <div class="mt-3 flex items-end justify-between gap-2">
                                        <span class="text-base font-bold text-brand-700 dark:text-brand-300" x-text="money(product.price)"></span>
                                        <span class="text-[11px] text-slate-500" x-text="product.type === 'service' ? @js(__('pos.service')) : (product.stock > 0 ? @js(__('pos.available')) + ': ' + product.stock : @js(__('pos.out_of_stock')))"></span>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </div>
                </section>

                <aside class="flex min-h-0 flex-col rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between border-b border-slate-100 p-4 dark:border-slate-800">
                        <div>
                            <h2 class="font-bold text-slate-950 dark:text-white">{{ __('pos.cart') }}</h2>
                            <p class="text-xs text-slate-500">{{ $register->name }} · {{ $register->warehouse?->name }}</p>
                        </div>
                        <button type="button" @click="cart=[]" class="text-xs font-semibold text-rose-600 hover:text-rose-700">{{ __('pos.clear_cart') }}</button>
                    </div>

                    <div class="min-h-0 flex-1 overflow-y-auto p-4">
                        <template x-if="cart.length === 0">
                            <div class="flex h-full min-h-52 flex-col items-center justify-center text-center text-slate-400">
                                <x-ui.icon name="shopping-cart" class="size-10" />
                                <p class="mt-3 text-sm">{{ __('pos.empty_cart') }}</p>
                            </div>
                        </template>

                        <div class="space-y-3">
                            <template x-for="item in cart" :key="item.id">
                                <div class="rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <div class="truncate text-sm font-semibold text-slate-900 dark:text-white" x-text="item.name"></div>
                                            <div class="mt-1 text-xs text-slate-500" x-text="money(item.price)"></div>
                                        </div>
                                        <button type="button" @click="remove(item.id)" class="text-slate-400 hover:text-rose-600">
                                            <x-ui.icon name="x-mark" class="size-4" />
                                        </button>
                                    </div>
                                    <div class="mt-3 flex items-center justify-between gap-3">
                                        <div class="inline-flex items-center rounded-lg border border-slate-200 dark:border-slate-700">
                                            <button type="button" @click="decrease(item)" class="px-3 py-1.5 text-sm font-bold">−</button>
                                            <span class="min-w-10 text-center text-sm font-semibold" x-text="item.quantity"></span>
                                            <button type="button" @click="add(item)" :disabled="!canAdd(item)" class="px-3 py-1.5 text-sm font-bold disabled:opacity-30">+</button>
                                        </div>
                                        <span class="text-sm font-bold text-slate-900 dark:text-white" x-text="money(lineSubtotal(item) + lineTax(item))"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    @can('pos.sell')
                    <form method="POST" action="{{ route('pos.checkout', $openShift) }}" class="border-t border-slate-100 p-4 dark:border-slate-800">
                        @csrf
                        <input type="hidden" name="items" :value="payload()">

                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between"><span class="text-slate-500">{{ __('pos.subtotal') }}</span><strong x-text="money(subtotal)"></strong></div>
                            <div class="flex justify-between"><span class="text-slate-500">{{ __('pos.tax') }}</span><strong x-text="money(tax)"></strong></div>
                            <div class="flex items-center justify-between gap-4">
                                <label for="discount_amount" class="text-slate-500">{{ __('pos.discount') }}</label>
                                <input id="discount_amount" name="discount_amount" x-model.number="discount" type="number" min="0" step="0.0001" class="w-32 rounded-lg border-slate-300 py-1.5 text-end text-sm dark:border-slate-700 dark:bg-slate-950">
                            </div>
                            <div class="flex justify-between border-t border-dashed border-slate-200 pt-3 text-lg dark:border-slate-700"><span class="font-bold">{{ __('pos.total') }}</span><strong class="text-brand-700 dark:text-brand-300" x-text="money(total)"></strong></div>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-1 2xl:grid-cols-2">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">{{ __('pos.payment_method') }}</label>
                                <select name="payment_method" x-model="payment" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950">
                                    <option value="cash">{{ __('pos.cash') }}</option>
                                    <option value="card">{{ __('pos.card') }}</option>
                                    <option value="mobile">{{ __('pos.mobile') }}</option>
                                    <option value="credit">{{ __('pos.credit') }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">{{ __('pos.customer') }}</label>
                                <select name="customer_id" x-model="customer" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950">
                                    <option value="">{{ __('pos.walk_in') }}</option>
                                    @foreach($customers as $customer)
                                        <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div x-show="payment === 'cash'" x-cloak class="mt-3 grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">{{ __('pos.amount_tendered') }}</label>
                                <input name="amount_tendered" x-model.number="tendered" type="number" min="0" step="0.0001" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950">
                            </div>
                            <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-800">
                                <div class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{{ __('pos.change_due') }}</div>
                                <div class="mt-1 text-base font-bold" x-text="money(change)"></div>
                            </div>
                        </div>

                        <button
                            type="submit"
                            :disabled="cart.length === 0 || (payment === 'credit' && !customer) || (payment === 'cash' && (+tendered || 0) < total)"
                            class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-4 py-3 text-sm font-bold text-white shadow-sm transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-40"
                        >
                            <x-ui.icon name="check-circle" class="size-5" />
                            {{ __('pos.complete_sale') }}
                        </button>
                    </form>

                    <div class="no-print border-t border-slate-100 p-4 dark:border-slate-800">
                        <details>
                            <summary class="cursor-pointer text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('pos.close_shift') }}</summary>
                            <form method="POST" action="{{ route('pos.shifts.close', $openShift) }}" class="mt-3 space-y-3">
                                @csrf
                                <x-ui.input name="closing_cash" type="number" min="0" step="0.0001" :label="__('pos.closing_cash')" required />
                                <x-ui.input name="note" :label="__('pos.close_note')" />
                                <x-ui.button type="submit" variant="secondary" class="w-full justify-center">{{ __('pos.close_shift') }}</x-ui.button>
                            </form>
                        </details>
                    </div>
                    @endcan
                </aside>
            </div>

            <section class="no-print mt-4 rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h3 class="font-semibold text-slate-900 dark:text-white">{{ __('pos.recent_sales') }}</h3>
                <div class="mt-3 flex gap-3 overflow-x-auto pb-1">
                    @forelse($recentSales as $sale)
                        <a href="{{ route('pos.receipt', $sale) }}" class="min-w-44 rounded-lg border border-slate-200 p-3 hover:border-brand-300 dark:border-slate-700">
                            <div class="text-sm font-semibold">{{ $sale->sale_number }}</div>
                            <div class="mt-1 text-xs text-slate-500">{{ $sale->completed_at?->format('Y-m-d H:i') }}</div>
                            <div class="mt-2 font-bold">{{ number_format((float) $sale->total, 2) }} {{ $sale->currency_code }}</div>
                        </a>
                    @empty
                        <p class="text-sm text-slate-500">{{ __('pos.empty_cart') }}</p>
                    @endforelse
                </div>
            </section>
        @endif
    </main>
</div>
@endsection
