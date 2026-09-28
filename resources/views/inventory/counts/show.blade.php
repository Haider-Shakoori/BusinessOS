@extends('layouts.app')

@section('content')
<x-app.page icon="clipboard-document-check" :title="$count->number" :subtitle="__('operations.stock_counts.detail_subtitle')">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="{{ route('inventory.counts.index') }}" variant="secondary">{{ __('operations.stock_counts.back') }}</x-ui.button>
            <x-ui.button href="{{ route('inventory.index') }}" variant="secondary">{{ __('operations.inventory.title') }}</x-ui.button>
        </div>
    </x-slot:actions>

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif
    @if ($errors->any())
        <div class="mb-5"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div>
    @endif

    <div class="grid gap-4 md:grid-cols-4">
        <x-ui.card><div class="text-sm text-slate-500">{{ __('operations.stock_counts.warehouse') }}</div><div class="mt-1 font-semibold">{{ $count->warehouse?->name }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">{{ __('operations.stock_counts.date') }}</div><div class="mt-1 font-semibold">{{ $count->count_date?->format('Y-m-d') }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">{{ __('operations.stock_counts.positive_variance') }}</div><div class="mt-1 font-semibold">{{ $count->total_positive_variance_value }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">{{ __('operations.stock_counts.negative_variance') }}</div><div class="mt-1 font-semibold">{{ $count->total_negative_variance_value }}</div></x-ui.card>
    </div>

    @if($count->status === 'draft')
        @can('inventory.manage')
            <x-ui.card class="mt-5">
                <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.stock_counts.add_item') }}</h2></x-slot:header>
                <form method="POST" action="{{ route('inventory.counts.items.store', $count) }}" class="grid gap-4 md:grid-cols-4">
                    @csrf
                    <x-ui.select name="product_id" :label="__('operations.stock_counts.product')" required>
                        @foreach($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }} @if($product->sku) ({{ $product->sku }}) @endif</option>
                        @endforeach
                    </x-ui.select>
                    <x-ui.select name="product_variant_id" :label="__('products.variants.select')">
                        <option value="">{{ __('products.variants.no_variant') }}</option>
                        @foreach($products as $product)
                            @foreach($product->variants as $variant)
                                <option value="{{ $variant->id }}">{{ $product->name }} — {{ $variant->name }}</option>
                            @endforeach
                        @endforeach
                    </x-ui.select>
                    <x-ui.input name="unit_cost" type="number" min="0" step="0.0001" :label="__('operations.stock_counts.unit_cost')" />
                    <div class="flex items-end justify-end">
                        <x-ui.button type="submit" icon="plus">{{ __('operations.stock_counts.add_item') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endcan
    @endif

    <x-ui.card class="mt-5">
        <x-slot:header>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.stock_counts.count_lines') }}</h2>
                <x-ui.badge tone="{{ $count->status === 'posted' ? 'success' : ($count->status === 'submitted' ? 'brand' : 'neutral') }}">
                    {{ __('operations.stock_counts.status_'.$count->status) }}
                </x-ui.badge>
            </div>
        </x-slot:header>

        <div class="overflow-x-auto">
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('operations.stock_counts.product') }}</x-ui.th>
                        <x-ui.th>{{ __('products.variants.select') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.stock_counts.expected') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.stock_counts.unit_cost') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.stock_counts.counted') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.stock_counts.variance') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.stock_counts.variance_value') }}</x-ui.th>
                        @if($count->status === 'draft')<x-ui.th></x-ui.th>@endif
                    </tr>
                </x-slot:head>
                @forelse($count->items as $item)
                    <tr>
                        <x-ui.td>{{ $item->product?->name }}</x-ui.td>
                        <x-ui.td>{{ $item->variant?->name ?: '—' }}</x-ui.td>
                        <x-ui.td>{{ $item->expected_quantity }}</x-ui.td>
                        <x-ui.td>
                            @if($count->status === 'draft' && (float) $item->expected_quantity === 0.0)
                                <input form="count-line-{{ $item->id }}" class="w-28 rounded-lg border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" name="unit_cost" type="number" min="0" step="0.0001" value="{{ $item->unit_cost }}">
                            @else
                                {{ $item->unit_cost }}
                            @endif
                        </x-ui.td>
                        <x-ui.td>
                            @if($count->status === 'draft')
                                <form id="count-line-{{ $item->id }}" method="POST" action="{{ route('inventory.counts.items.update', [$count, $item]) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input class="w-28 rounded-lg border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900" name="counted_quantity" type="number" min="0" step="0.0001" value="{{ $item->counted_quantity }}" required>
                                </form>
                            @else
                                {{ $item->counted_quantity }}
                            @endif
                        </x-ui.td>
                        <x-ui.td>{{ $item->variance_quantity }}</x-ui.td>
                        <x-ui.td>{{ $item->variance_value }}</x-ui.td>
                        @if($count->status === 'draft')
                            <x-ui.td><x-ui.button type="submit" form="count-line-{{ $item->id }}" size="sm">{{ __('operations.stock_counts.save_count') }}</x-ui.button></x-ui.td>
                        @endif
                    </tr>
                @empty
                    <tr><x-ui.td colspan="8">{{ __('operations.stock_counts.no_items') }}</x-ui.td></tr>
                @endforelse
            </x-ui.table>
        </div>
    </x-ui.card>

    @can('inventory.manage')
        <div class="mt-5 flex justify-end gap-3">
            @if($count->status === 'draft')
                <form method="POST" action="{{ route('inventory.counts.submit', $count) }}">
                    @csrf
                    <x-ui.button type="submit">{{ __('operations.stock_counts.submit') }}</x-ui.button>
                </form>
            @elseif($count->status === 'submitted')
                <form method="POST" action="{{ route('inventory.counts.post', $count) }}">
                    @csrf
                    <x-ui.button type="submit">{{ __('operations.stock_counts.approve_post') }}</x-ui.button>
                </form>
            @endif
        </div>
    @endcan
</x-app.page>
@endsection
