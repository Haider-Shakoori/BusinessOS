@extends('layouts.app')

@section('content')
@php
    $formatter = app(\App\Support\LocalizedFormatter::class);
    $money = fn ($amount) => $formatter->currency((string) $amount, $baseCurrency);
    $number = fn ($amount) => $formatter->number((string) $amount, 4);
@endphp

<x-app.page icon="chart-bar-square" :title="__('operations.inventory_intelligence.title')" :subtitle="__('operations.inventory_intelligence.subtitle')">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="{{ route('inventory.index') }}" variant="secondary">{{ __('operations.inventory.title') }}</x-ui.button>
            @can('inventory.manage')
                <form method="POST" action="{{ route('inventory.intelligence.alerts.refresh') }}">
                    @csrf
                    <x-ui.button type="submit" icon="bell-alert">{{ __('operations.inventory_intelligence.refresh_alerts') }}</x-ui.button>
                </form>
            @endcan
        </div>
    </x-slot:actions>

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif

    <x-ui.card>
        <form method="GET" action="{{ route('inventory.intelligence.index') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
            <x-ui.select name="warehouse_id" :label="__('operations.inventory_intelligence.warehouse')">
                <option value="">{{ __('operations.inventory_intelligence.all_warehouses') }}</option>
                @foreach($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected($filters['warehouse_id'] === $warehouse->id)>
                        {{ $warehouse->name }}@if($warehouse->is_default) — {{ __('operations.warehouses.default') }}@endif
                    </option>
                @endforeach
            </x-ui.select>
            <x-ui.select name="product_id" :label="__('operations.inventory_intelligence.product')">
                <option value="">{{ __('operations.inventory_intelligence.all_products') }}</option>
                @foreach($products as $product)
                    <option value="{{ $product->id }}" @selected($filters['product_id'] === $product->id)>
                        {{ $product->name }}@if($product->sku) — {{ $product->sku }}@endif
                    </option>
                @endforeach
            </x-ui.select>
            <x-ui.select name="slow_days" :label="__('operations.inventory_intelligence.slow_days')">
                @foreach([30, 60, 90, 180, 365] as $days)
                    <option value="{{ $days }}" @selected($filters['slow_days'] === $days)>
                        {{ __('operations.inventory_intelligence.days', ['count' => $days]) }}
                    </option>
                @endforeach
            </x-ui.select>
            <x-ui.input name="date_from" type="date" :label="__('operations.inventory_intelligence.date_from')" :value="$data['date_from']" />
            <x-ui.input name="date_to" type="date" :label="__('operations.inventory_intelligence.date_to')" :value="$data['date_to']" />
            <div class="md:col-span-2 xl:col-span-5 flex justify-end">
                <x-ui.button type="submit" variant="secondary" icon="funnel">{{ __('operations.inventory_intelligence.apply_filters') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card :title="__('operations.inventory_intelligence.stock_value')" :value="$money($data['summary']['stock_value'])" icon="banknotes" tone="brand" />
        <x-ui.stat-card :title="__('operations.inventory_intelligence.stock_quantity')" :value="$number($data['summary']['stock_quantity'])" icon="archive-box" tone="info" />
        <x-ui.stat-card :title="__('operations.inventory_intelligence.low_stock')" :value="$data['summary']['low_stock']" icon="exclamation-triangle" tone="warning" />
        <x-ui.stat-card :title="__('operations.inventory_intelligence.out_of_stock')" :value="$data['summary']['out_of_stock']" icon="x-circle" tone="danger" />
        <x-ui.stat-card :title="__('operations.inventory_intelligence.replenishing')" :value="$data['summary']['replenishing']" icon="arrow-path" tone="neutral" />
        <x-ui.stat-card :title="__('operations.inventory_intelligence.slow_moving')" :value="$data['summary']['slow_moving']" icon="clock" tone="warning" />
        <x-ui.stat-card :title="__('operations.inventory_intelligence.inbound_quantity')" :value="$number($data['summary']['inbound_quantity'])" icon="arrow-down-tray" tone="success" />
        <x-ui.stat-card :title="__('operations.inventory_intelligence.outbound_quantity')" :value="$number($data['summary']['outbound_quantity'])" icon="arrow-up-tray" tone="neutral" />
    </div>

    <div class="mt-5">
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.inventory_intelligence.valuation_title') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('operations.inventory_intelligence.valuation_help', ['days' => $data['slow_days']]) }}</p>
                </div>
            </x-slot:header>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('operations.inventory_intelligence.warehouse') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.inventory_intelligence.product') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.inventory_intelligence.variant') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.inventory_intelligence.quantity') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.inventory_intelligence.average_cost') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.inventory_intelligence.stock_value') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.inventory_intelligence.last_activity') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.inventory_intelligence.inactive_days') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.inventory_intelligence.health') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    @forelse($data['valuation_rows'] as $row)
                        <tr>
                            <x-ui.td>{{ $row['warehouse']?->name ?? '—' }}</x-ui.td>
                            <x-ui.td>{{ $row['product']?->name ?? '—' }}</x-ui.td>
                            <x-ui.td>{{ $row['variant']?->name ?? '—' }}</x-ui.td>
                            <x-ui.td>{{ $number($row['quantity']) }}</x-ui.td>
                            <x-ui.td>{{ $money($row['average_unit_cost']) }}</x-ui.td>
                            <x-ui.td>{{ $money($row['stock_value']) }}</x-ui.td>
                            <x-ui.td>{{ $row['last_movement_at']?->format('Y-m-d H:i') ?? '—' }}</x-ui.td>
                            <x-ui.td>{{ $row['inactive_days'] }}</x-ui.td>
                            <x-ui.td>
                                @if(!$row['valuation_complete'])
                                    <x-ui.badge tone="warning">{{ __('operations.inventory_intelligence.incomplete_cost') }}</x-ui.badge>
                                @elseif($row['is_slow'])
                                    <x-ui.badge tone="warning">{{ __('operations.inventory_intelligence.slow') }}</x-ui.badge>
                                @else
                                    <x-ui.badge tone="success">{{ __('operations.inventory_intelligence.active_stock') }}</x-ui.badge>
                                @endif
                            </x-ui.td>
                        </tr>
                    @empty
                        <tr><x-ui.td colspan="9">{{ __('operations.inventory_intelligence.no_stock') }}</x-ui.td></tr>
                    @endforelse
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>

    <div class="mt-5">
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.inventory_intelligence.movements_title') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ __('operations.inventory_intelligence.movements_help', ['from' => $data['date_from'], 'to' => $data['date_to']]) }}
                    </p>
                </div>
            </x-slot:header>
            <div class="mb-4 grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
                    <div class="text-slate-500">{{ __('operations.inventory_intelligence.inbound_quantity') }}</div>
                    <div class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $number($data['summary']['inbound_quantity']) }}</div>
                </div>
                <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
                    <div class="text-slate-500">{{ __('operations.inventory_intelligence.outbound_quantity') }}</div>
                    <div class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $number($data['summary']['outbound_quantity']) }}</div>
                </div>
                <div class="rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
                    <div class="text-slate-500">{{ __('operations.inventory_intelligence.net_movement') }}</div>
                    <div class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $number($data['summary']['net_movement']) }}</div>
                </div>
            </div>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('operations.inventory_intelligence.date') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.inventory_intelligence.warehouse') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.inventory_intelligence.product') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.inventory_intelligence.type') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.inventory_intelligence.quantity') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.inventory_intelligence.unit_cost') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.inventory_intelligence.note') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    @forelse($data['movements'] as $movement)
                        <tr>
                            <x-ui.td>{{ $movement->occurred_at?->format('Y-m-d H:i') }}</x-ui.td>
                            <x-ui.td>{{ $movement->warehouse?->name ?? '—' }}</x-ui.td>
                            <x-ui.td>{{ $movement->product?->name ?? '—' }}@if($movement->variant) — {{ $movement->variant->name }}@endif</x-ui.td>
                            <x-ui.td>{{ __('operations.inventory.'.$movement->type) }}</x-ui.td>
                            <x-ui.td>{{ $number($movement->quantity) }}</x-ui.td>
                            <x-ui.td>{{ $movement->unit_cost !== null ? $money($movement->unit_cost) : '—' }}</x-ui.td>
                            <x-ui.td>{{ $movement->note ?: '—' }}</x-ui.td>
                        </tr>
                    @empty
                        <tr><x-ui.td colspan="7">{{ __('operations.inventory_intelligence.no_movements') }}</x-ui.td></tr>
                    @endforelse
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>
</x-app.page>
@endsection
