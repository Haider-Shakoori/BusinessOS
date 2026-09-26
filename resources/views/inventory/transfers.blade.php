@extends('layouts.app')

@section('content')
<x-app.page icon="arrow-right" :title="__('operations.transfers.title')" :subtitle="__('operations.transfers.subtitle')">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="{{ route('inventory.returns.index') }}" variant="secondary">{{ __('operations.returns.title') }}</x-ui.button>
            <x-ui.button href="{{ route('inventory.index') }}" variant="secondary">{{ __('operations.inventory.title') }}</x-ui.button>
        </div>
    </x-slot:actions>

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif

    @if ($errors->any())
        <div class="mb-5"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div>
    @endif

    <x-ui.card>
        <x-slot:header>
            <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.transfers.new') }}</h2>
        </x-slot:header>

        <form method="POST" action="{{ route('inventory.transfers.store') }}" class="grid gap-4 lg:grid-cols-6">
            @csrf
            <x-ui.select name="source_warehouse_id" :label="__('operations.transfers.source')" required>
                @foreach($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select name="destination_warehouse_id" :label="__('operations.transfers.destination')" required>
                @foreach($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select name="product_id" :label="__('operations.transfers.product')" required>
                @foreach($products as $product)
                    <option value="{{ $product->id }}">{{ $product->name }} @if($product->sku) ({{ $product->sku }}) @endif</option>
                @endforeach
            </x-ui.select>
            <x-ui.select name="product_variant_id" :label="__('products.variants.select')">
                <option value="">{{ __('products.variants.no_variant') }}</option>
                @foreach($products as $product)
                    @foreach($product->variants as $variant)
                        <option value="{{ $variant->id }}">{{ $product->name }} — {{ $variant->name }} @if($variant->sku) ({{ $variant->sku }}) @endif</option>
                    @endforeach
                @endforeach
            </x-ui.select>
            <x-ui.input name="quantity" type="number" step="0.0001" min="0.0001" :label="__('operations.transfers.quantity')" required />
            <x-ui.input name="note" :label="__('operations.transfers.note')" />
            <div class="lg:col-span-6 flex justify-end">
                <x-ui.button type="submit" icon="plus">{{ __('operations.transfers.create') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card class="mt-5">
        <x-slot:header>
            <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.transfers.history') }}</h2>
        </x-slot:header>

        <div class="overflow-x-auto">
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('operations.transfers.number') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.transfers.route') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.transfers.product') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.transfers.quantity') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.transfers.status') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.transfers.date') }}</x-ui.th>
                        <x-ui.th></x-ui.th>
                    </tr>
                </x-slot:head>
                @forelse($transfers as $transfer)
                    @php($item = $transfer->items->first())
                    <tr>
                        <x-ui.td>{{ $transfer->number }}</x-ui.td>
                        <x-ui.td>{{ $transfer->sourceWarehouse?->name }} → {{ $transfer->destinationWarehouse?->name }}</x-ui.td>
                        <x-ui.td>{{ $item?->product?->name }} @if($item?->variant)<span class="text-slate-500">— {{ $item->variant->name }}</span>@endif</x-ui.td>
                        <x-ui.td>{{ $item?->quantity }}</x-ui.td>
                        <x-ui.td><x-ui.badge tone="{{ $transfer->status === 'received' ? 'success' : ($transfer->status === 'in_transit' ? 'brand' : 'neutral') }}">{{ __('operations.transfers.'.$transfer->status) }}</x-ui.badge></x-ui.td>
                        <x-ui.td>{{ $transfer->transfer_date?->format('Y-m-d') }}</x-ui.td>
                        <x-ui.td>
                            @can('inventory.manage')
                                @if($transfer->status === 'draft')
                                    <form method="POST" action="{{ route('inventory.transfers.dispatch', $transfer) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" variant="secondary">{{ __('operations.transfers.dispatch') }}</x-ui.button>
                                    </form>
                                @elseif($transfer->status === 'in_transit')
                                    <form method="POST" action="{{ route('inventory.transfers.receive', $transfer) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm">{{ __('operations.transfers.receive') }}</x-ui.button>
                                    </form>
                                @endif
                            @endcan
                        </x-ui.td>
                    </tr>
                @empty
                    <tr><x-ui.td colspan="7">{{ __('operations.transfers.empty') }}</x-ui.td></tr>
                @endforelse
            </x-ui.table>
        </div>
    </x-ui.card>
</x-app.page>
@endsection
