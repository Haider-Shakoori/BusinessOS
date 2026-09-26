@extends('layouts.app')

@section('content')
<x-app.page icon="arrow-uturn-left" :title="__('operations.returns.title')" :subtitle="__('operations.returns.subtitle')">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="{{ route('inventory.transfers.index') }}" variant="secondary">{{ __('operations.transfers.title') }}</x-ui.button>
            <x-ui.button href="{{ route('inventory.index') }}" variant="secondary">{{ __('operations.inventory.title') }}</x-ui.button>
        </div>
    </x-slot:actions>

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif

    @if ($errors->any())
        <div class="mb-5"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div>
    @endif

    <div class="grid gap-5 xl:grid-cols-2">
        @if($canSalesReturn)
            <x-ui.card>
                <x-slot:header>
                    <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.returns.sales_return') }}</h2>
                </x-slot:header>
                <form method="POST" action="{{ route('inventory.returns.sales.store') }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    <div class="sm:col-span-2">
                        <x-ui.select name="pos_sale_item_id" :label="__('operations.returns.sale_item')" required>
                            @foreach($sales as $sale)
                                @foreach($sale->items as $item)
                                    <option value="{{ $item->id }}">{{ $sale->sale_number }} — {{ $item->product_name }} — {{ $item->quantity }}</option>
                                @endforeach
                            @endforeach
                        </x-ui.select>
                    </div>
                    <x-ui.input name="quantity" type="number" step="0.0001" min="0.0001" :label="__('operations.returns.quantity')" required />
                    <x-ui.input name="reason" :label="__('operations.returns.reason')" required />
                    <div class="sm:col-span-2 flex justify-end">
                        <x-ui.button type="submit">{{ __('operations.returns.process_sales') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        @if($canPurchaseReturn)
            <x-ui.card>
                <x-slot:header>
                    <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.returns.purchase_return') }}</h2>
                </x-slot:header>
                <form method="POST" action="{{ route('inventory.returns.purchases.store') }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    <div class="sm:col-span-2">
                        <x-ui.select name="purchase_order_item_id" :label="__('operations.returns.purchase_item')" required>
                            @foreach($purchases as $purchase)
                                @foreach($purchase->items as $item)
                                    <option value="{{ $item->id }}">{{ $purchase->number }} — {{ $item->product?->name }} — {{ $item->quantity }}</option>
                                @endforeach
                            @endforeach
                        </x-ui.select>
                    </div>
                    <x-ui.select name="warehouse_id" :label="__('operations.returns.warehouse')" required>
                        @foreach($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.input name="quantity" type="number" step="0.0001" min="0.0001" :label="__('operations.returns.quantity')" required />
                    <div class="sm:col-span-2"><x-ui.input name="reason" :label="__('operations.returns.reason')" required /></div>
                    <div class="sm:col-span-2 flex justify-end">
                        <x-ui.button type="submit">{{ __('operations.returns.process_purchase') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif
    </div>

    <x-ui.card class="mt-5">
        <x-slot:header>
            <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.returns.history') }}</h2>
        </x-slot:header>

        <div class="overflow-x-auto">
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('operations.returns.number') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.returns.type') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.returns.product') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.returns.quantity') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.returns.warehouse') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.returns.total') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.returns.date') }}</x-ui.th>
                    </tr>
                </x-slot:head>
                @forelse($returns as $return)
                    @php($item = $return->items->first())
                    <tr>
                        <x-ui.td>{{ $return->number }}</x-ui.td>
                        <x-ui.td><x-ui.badge tone="{{ $return->type === 'sales' ? 'success' : 'warning' }}">{{ __('operations.returns.'.$return->type) }}</x-ui.badge></x-ui.td>
                        <x-ui.td>{{ $item?->product?->name }}</x-ui.td>
                        <x-ui.td>{{ $item?->quantity }}</x-ui.td>
                        <x-ui.td>{{ $return->warehouse?->name }}</x-ui.td>
                        <x-ui.td>{{ $return->total }}</x-ui.td>
                        <x-ui.td>{{ $return->processed_at?->format('Y-m-d H:i') }}</x-ui.td>
                    </tr>
                @empty
                    <tr><x-ui.td colspan="7">{{ __('operations.returns.empty') }}</x-ui.td></tr>
                @endforelse
            </x-ui.table>
        </div>
    </x-ui.card>
</x-app.page>
@endsection
