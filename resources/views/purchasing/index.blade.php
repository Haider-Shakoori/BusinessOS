@extends('layouts.app')

@section('content')
<x-app.page icon="shopping-cart" :title="__('operations.purchasing.title')" :subtitle="__('operations.purchasing.subtitle')">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="{{ route('purchasing.requisitions.index') }}" variant="secondary" icon="document-text">{{ __('operations.purchasing.requisitions') }}</x-ui.button>
            <x-ui.button href="{{ route('purchasing.supplier-invoices.index') }}" variant="secondary" icon="document-text">{{ __('operations.purchasing.supplier_invoices') }}</x-ui.button>
            <x-ui.button href="{{ route('suppliers.index') }}" variant="secondary" icon="users">{{ __('suppliers.title') }}</x-ui.button>
        </div>
    </x-slot:actions>
    @can('inventory.view')
        <x-slot:actions>
            <x-ui.button href="{{ route('inventory.returns.index') }}" variant="secondary">{{ __('operations.returns.title') }}</x-ui.button>
        </x-slot:actions>
    @endcan
    @if (session('status')) <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div> @endif

    <div class="grid gap-5 xl:grid-cols-2">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.purchasing.add_supplier') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('purchasing.suppliers.store') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-ui.input name="name" :label="__('operations.purchasing.supplier')" required />
                <x-ui.input name="email" type="email" :label="__('operations.purchasing.email')" />
                <x-ui.input name="phone" :label="__('operations.purchasing.phone')" />
                <x-ui.input name="address" :label="__('operations.purchasing.address')" />
                <div class="sm:col-span-2 flex justify-end"><x-ui.button type="submit" icon="plus">{{ __('operations.purchasing.add_supplier') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.purchasing.new_order') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('purchasing.orders.store') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-ui.select name="supplier_id" :label="__('operations.purchasing.supplier')" required>
                    @foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.input name="number" :label="__('operations.purchasing.number')" required />
                <x-ui.input name="order_date" type="date" :label="__('operations.purchasing.order_date')" :value="now()->toDateString()" required />
                <x-ui.input name="expected_date" type="date" :label="__('operations.purchasing.expected_date')" />
                <x-ui.select name="product_id" :label="__('operations.purchasing.product')" required>
                    @foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.select name="product_variant_id" :label="__('products.variants.select')">
                    <option value="">{{ __('products.variants.no_variant') }}</option>
                    @foreach($products as $product)
                        @foreach($product->variants as $variant)
                            <option value="{{ $variant->id }}">{{ $product->name }} — {{ $variant->name }} @if($variant->sku) ({{ $variant->sku }}) @endif</option>
                        @endforeach
                    @endforeach
                </x-ui.select>
                <x-ui.input name="quantity" type="number" step="0.0001" min="0.0001" :label="__('operations.purchasing.quantity')" required />
                <x-ui.input name="unit_cost" type="number" step="0.0001" min="0" :label="__('operations.purchasing.unit_cost')" required />
                <x-ui.input name="notes" :label="__('operations.purchasing.notes')" />
                <div class="sm:col-span-2 flex justify-end"><x-ui.button type="submit" icon="plus">{{ __('operations.purchasing.new_order') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-3">
        <x-ui.card class="xl:col-span-1">
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.purchasing.suppliers') }}</h2></x-slot:header>
            <div class="space-y-3">
                @foreach($suppliers as $supplier)
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <div class="font-medium text-slate-900 dark:text-white">{{ $supplier->name }}</div>
                        <div class="text-xs text-slate-500">{{ $supplier->phone }} @if($supplier->email) · {{ $supplier->email }} @endif</div>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card class="xl:col-span-2">
            <x-slot:header>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.purchasing.purchase_orders') }}</h2>
                    <x-ui.button href="{{ route('purchasing.rfqs.index') }}" size="sm" variant="secondary">
                        {{ __('operations.purchasing.rfqs') }}
                    </x-ui.button>
                </div>
            </x-slot:header>

            <div class="space-y-4">
                @forelse($orders as $order)
                    @php($remaining = $remainingByOrder->get($order->id, collect()))
                    <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-semibold text-slate-900 dark:text-white">{{ $order->number }}</h3>
                                    <x-ui.badge :tone="$order->status === 'received' ? 'success' : ($order->status === 'partially_received' ? 'warning' : 'neutral')">
                                        {{ ucfirst(str_replace('_', ' ', $order->status)) }}
                                    </x-ui.badge>
                                </div>
                                <div class="mt-1 text-sm text-slate-500">
                                    {{ $order->supplier?->name }}
                                    · {{ __('operations.purchasing.total') }}: {{ $order->total }}
                                </div>
                                @if($order->supplierQuotation || $order->rfq || $order->requisition)
                                    <div class="mt-1 text-xs text-slate-500">
                                        {{ __('operations.purchasing.source') }}:
                                        @if($order->requisition) {{ $order->requisition->number }} @endif
                                        @if($order->rfq) · {{ $order->rfq->number }} @endif
                                        @if($order->supplierQuotation) · {{ $order->supplierQuotation->number }} @endif
                                    </div>
                                @endif
                            </div>
                            <div class="text-xs text-slate-500">
                                {{ __('operations.purchasing.order_date') }}: {{ $order->order_date?->format('Y-m-d') }}
                                @if($order->expected_date)
                                    · {{ __('operations.purchasing.expected_date') }}: {{ $order->expected_date->format('Y-m-d') }}
                                @endif
                            </div>
                        </div>

                        <div class="mt-4 overflow-x-auto">
                            <x-ui.table>
                                <x-slot:head>
                                    <tr>
                                        <x-ui.th>{{ __('operations.purchasing.product') }}</x-ui.th>
                                        <x-ui.th>{{ __('operations.purchasing.quantity') }}</x-ui.th>
                                        <x-ui.th>{{ __('operations.purchasing.remaining') }}</x-ui.th>
                                        <x-ui.th>{{ __('operations.purchasing.unit_cost') }}</x-ui.th>
                                        <x-ui.th>{{ __('operations.purchasing.total') }}</x-ui.th>
                                    </tr>
                                </x-slot:head>
                                @foreach($order->items as $item)
                                    <tr>
                                        <x-ui.td>
                                            {{ $item->product?->name }}
                                            @if($item->variant)<span class="text-slate-500">— {{ $item->variant->name }}</span>@endif
                                        </x-ui.td>
                                        <x-ui.td>{{ $item->quantity }}</x-ui.td>
                                        <x-ui.td>{{ $remaining->get($item->id, '0.0000') }}</x-ui.td>
                                        <x-ui.td>{{ $item->unit_cost }}</x-ui.td>
                                        <x-ui.td>{{ $item->line_total }}</x-ui.td>
                                    </tr>
                                @endforeach
                            </x-ui.table>
                        </div>

                        @can('purchasing.manage')
                            @if(in_array($order->status, ['draft', 'ordered', 'partially_received'], true) && $warehouses->isNotEmpty())
                                <details class="mt-4 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                    <summary class="cursor-pointer text-sm font-medium text-blue-600 dark:text-blue-400">
                                        {{ __('operations.purchasing.receive_goods') }}
                                    </summary>
                                    <form method="POST" action="{{ route('purchasing.orders.receive', $order) }}" class="mt-3 space-y-4">
                                        @csrf
                                        <div class="grid gap-3 md:grid-cols-3">
                                            <x-ui.select name="warehouse_id" :label="__('operations.purchasing.warehouse')" required>
                                                @foreach($warehouses as $warehouse)
                                                    <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>
                                                @endforeach
                                            </x-ui.select>
                                            <x-ui.input name="receipt_date" type="date" :label="__('operations.purchasing.receipt_date')" :value="now()->toDateString()" required />
                                            <x-ui.input name="notes" :label="__('operations.purchasing.notes')" />
                                        </div>

                                        <div class="grid gap-3 md:grid-cols-2">
                                            @foreach($order->items as $receiptIndex => $item)
                                                @php($remainingQty = $remaining->get($item->id, '0.0000'))
                                                <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                                                    <div class="mb-2 text-sm font-medium text-slate-900 dark:text-white">
                                                        {{ $item->product?->name }}
                                                        @if($item->variant) — {{ $item->variant->name }} @endif
                                                    </div>
                                                    <input type="hidden" name="items[{{ $receiptIndex }}][purchase_order_item_id]" value="{{ $item->id }}">
                                                    <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">
                                                        {{ __('operations.purchasing.receive_quantity') }}
                                                        <span class="text-slate-400">({{ __('operations.purchasing.remaining') }}: {{ $remainingQty }})</span>
                                                    </label>
                                                    <input
                                                        name="items[{{ $receiptIndex }}][quantity]"
                                                        type="number"
                                                        step="0.0001"
                                                        min="0"
                                                        max="{{ $remainingQty }}"
                                                        value="{{ $remainingQty }}"
                                                        class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950"
                                                    >
                                                </div>
                                            @endforeach
                                        </div>

                                        <div class="flex justify-end">
                                            <x-ui.button type="submit" size="sm">{{ __('operations.purchasing.post_goods_receipt') }}</x-ui.button>
                                        </div>
                                    </form>
                                </details>
                            @endif
                        @endcan

                        @if($order->goodsReceipts->isNotEmpty())
                            <div class="mt-4">
                                <h4 class="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('operations.purchasing.goods_receipts') }}</h4>
                                <div class="grid gap-2 md:grid-cols-2">
                                    @foreach($order->goodsReceipts as $receipt)
                                        <div class="rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700">
                                            <div class="flex items-center justify-between gap-2">
                                                <span class="font-medium text-slate-900 dark:text-white">{{ $receipt->number }}</span>
                                                <span class="text-xs text-slate-500">{{ $receipt->receipt_date?->format('Y-m-d') }}</span>
                                            </div>
                                            <div class="mt-1 text-xs text-slate-500">
                                                {{ $receipt->warehouse?->name }}
                                                · {{ __('operations.purchasing.total') }}: {{ $receipt->total }}
                                                @if($receipt->receiver) · {{ $receipt->receiver->name }} @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        @can('accounting.manage')
                            @if($order->ap_recognition === 'receipt' && in_array($order->status, ['partially_received', 'received'], true))
                                <details class="mt-4">
                                    <summary class="cursor-pointer text-xs font-medium text-slate-500">{{ __('operations.purchasing.post_debit_note') }}</summary>
                                    <form method="POST" action="{{ route('accounting.debit-notes.store', $order) }}" class="mt-2 flex max-w-md items-end gap-2">
                                        @csrf
                                        <x-ui.input name="amount" type="number" step="0.0001" min="0.0001" :label="__('operations.purchasing.debit_note')" required />
                                        <input type="hidden" name="note_date" value="{{ now()->toDateString() }}">
                                        <x-ui.button type="submit" size="sm" variant="secondary">{{ __('operations.purchasing.post') }}</x-ui.button>
                                    </form>
                                </details>
                            @endif
                        @endcan
                    </div>
                @empty
                    <div class="text-sm text-slate-500">{{ __('operations.purchasing.no_orders') }}</div>
                @endforelse
            </div>
        </x-ui.card>
    </div>
</x-app.page>
@endsection
