@extends('layouts.app')

@section('content')
<x-app.page icon="shopping-cart" :title="__('operations.purchasing.title')" :subtitle="__('operations.purchasing.subtitle')">
    <x-slot:actions>
        <x-ui.button href="{{ route('suppliers.index') }}" variant="secondary" icon="users">{{ __('suppliers.title') }}</x-ui.button>
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
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.purchasing.purchase_orders') }}</h2></x-slot:header>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-slot:head><tr><x-ui.th>{{ __('operations.purchasing.number') }}</x-ui.th><x-ui.th>{{ __('operations.purchasing.supplier') }}</x-ui.th><x-ui.th>{{ __('operations.purchasing.status') }}</x-ui.th><x-ui.th>{{ __('operations.purchasing.total') }}</x-ui.th><x-ui.th></x-ui.th></tr></x-slot:head>
                    @forelse($orders as $order)
                        <tr>
                            <x-ui.td>{{ $order->number }}</x-ui.td>
                            <x-ui.td>{{ $order->supplier?->name }}</x-ui.td>
                            <x-ui.td>{{ ucfirst($order->status) }}</x-ui.td>
                            <x-ui.td>{{ $order->total }}</x-ui.td>
                            <x-ui.td>
                                @if($order->status !== 'received' && $warehouses->isNotEmpty())
                                    <form method="POST" action="{{ route('purchasing.orders.receive', $order) }}" class="flex items-center gap-2">
                                        @csrf
                                        <select name="warehouse_id" class="rounded-md border-slate-300 text-xs dark:border-slate-700 dark:bg-slate-900" required>
                                            @foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option>@endforeach
                                        </select>
                                        <x-ui.button type="submit" size="sm" variant="secondary">{{ __('operations.purchasing.receive') }}</x-ui.button>
                                    </form>
                                @endif
                            </x-ui.td>
                        </tr>
                    @empty
                        <tr><x-ui.td colspan="5">{{ __('operations.purchasing.no_orders') }}</x-ui.td></tr>
                    @endforelse
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>
</x-app.page>
@endsection
