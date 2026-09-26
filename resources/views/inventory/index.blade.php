@extends('layouts.app')

@section('content')
<x-app.page icon="archive-box" :title="__('operations.inventory.title')" :subtitle="__('operations.inventory.subtitle')">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="{{ route('inventory.transfers.index') }}" variant="secondary">{{ __('operations.transfers.title') }}</x-ui.button>
            <x-ui.button href="{{ route('inventory.returns.index') }}" variant="secondary">{{ __('operations.returns.title') }}</x-ui.button>
        </div>
    </x-slot:actions>
    @if (session('status')) <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div> @endif

    <div class="grid gap-5 xl:grid-cols-2">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.inventory.add_warehouse') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('inventory.warehouses.store') }}" class="grid gap-4 sm:grid-cols-3">
                @csrf
                <x-ui.input name="code" :label="__('operations.inventory.code')" required />
                <div class="sm:col-span-2"><x-ui.input name="name" :label="__('operations.inventory.name')" required /></div>
                <div class="sm:col-span-3 flex justify-end"><x-ui.button type="submit" icon="plus">{{ __('operations.inventory.add_warehouse') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.inventory.add_movement') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('inventory.movements.store') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-ui.select name="warehouse_id" :label="__('operations.inventory.warehouse')" required>
                    @foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.select name="product_id" :label="__('operations.inventory.product')" required>
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
                <x-ui.select name="type" :label="__('operations.inventory.type')" required>
                    @foreach(['opening','purchase','sale','adjustment','production_in','production_out'] as $type)
                        <option value="{{ $type }}">{{ __('operations.inventory.'.$type) }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="quantity" type="number" step="0.0001" :label="__('operations.inventory.quantity')" required />
                <x-ui.input name="unit_cost" type="number" step="0.0001" min="0" :label="__('operations.inventory.unit_cost')" />
                <x-ui.input name="note" :label="__('operations.inventory.note')" />
                <div class="sm:col-span-2 flex justify-end"><x-ui.button type="submit" icon="plus">{{ __('operations.inventory.add_movement') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.inventory.balances') }}</h2></x-slot:header>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-slot:head><tr><x-ui.th>{{ __('operations.inventory.product') }}</x-ui.th><x-ui.th>{{ __('products.variants.select') }}</x-ui.th><x-ui.th>{{ __('operations.inventory.warehouse') }}</x-ui.th><x-ui.th>{{ __('operations.inventory.quantity') }}</x-ui.th></tr></x-slot:head>
                    @forelse($balances as $balance)
                        <tr><x-ui.td>{{ $balance->product?->name }}</x-ui.td><x-ui.td>{{ $balance->variant?->name ?: '—' }}</x-ui.td><x-ui.td>{{ $balance->warehouse?->name }}</x-ui.td><x-ui.td>{{ $balance->quantity }}</x-ui.td></tr>
                    @empty
                        <tr><x-ui.td colspan="5">{{ __('operations.inventory.no_data') }}</x-ui.td></tr>
                    @endforelse
                </x-ui.table>
            </div>
        </x-ui.card>

        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.inventory.movements') }}</h2></x-slot:header>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-slot:head><tr><x-ui.th>{{ __('operations.inventory.product') }}</x-ui.th><x-ui.th>{{ __('products.variants.select') }}</x-ui.th><x-ui.th>{{ __('operations.inventory.type') }}</x-ui.th><x-ui.th>{{ __('operations.inventory.quantity') }}</x-ui.th><x-ui.th>{{ __('operations.inventory.occurred_at') }}</x-ui.th></tr></x-slot:head>
                    @forelse($movements as $movement)
                        <tr><x-ui.td>{{ $movement->product?->name }}</x-ui.td><x-ui.td>{{ $movement->variant?->name ?: '—' }}</x-ui.td><x-ui.td>{{ __('operations.inventory.'.$movement->type) }}</x-ui.td><x-ui.td>{{ $movement->quantity }}</x-ui.td><x-ui.td>{{ $movement->occurred_at?->format('Y-m-d H:i') }}</x-ui.td></tr>
                    @empty
                        <tr><x-ui.td colspan="4">{{ __('operations.inventory.no_data') }}</x-ui.td></tr>
                    @endforelse
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>
</x-app.page>
@endsection
