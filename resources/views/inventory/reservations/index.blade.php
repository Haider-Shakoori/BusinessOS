@extends('layouts.app')

@section('content')
<x-app.page icon="lock-closed" :title="__('operations.reservations.title')" :subtitle="__('operations.reservations.subtitle')">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="{{ route('inventory.index') }}" variant="secondary">{{ __('operations.inventory.title') }}</x-ui.button>
            <x-ui.button href="{{ route('inventory.locations.index') }}" variant="secondary">{{ __('operations.locations.title') }}</x-ui.button>
        </div>
    </x-slot:actions>

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif

    @if ($errors->any())
        <div class="mb-5"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div>
    @endif

    <x-ui.card>
        <form method="GET" action="{{ route('inventory.reservations.index') }}" class="grid gap-4 md:grid-cols-3">
            <x-ui.select name="warehouse_id" :label="__('operations.reservations.warehouse')">
                <option value="">{{ __('operations.reservations.all_warehouses') }}</option>
                @foreach($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected($selectedWarehouseId === $warehouse->id)>
                        {{ $warehouse->code }} — {{ $warehouse->name }}
                    </option>
                @endforeach
            </x-ui.select>
            <x-ui.select name="status" :label="__('operations.reservations.status')">
                <option value="">{{ __('operations.reservations.all_statuses') }}</option>
                @foreach($statuses as $status)
                    <option value="{{ $status }}" @selected($selectedStatus === $status)>
                        {{ __('operations.reservations.status_'.$status) }}
                    </option>
                @endforeach
            </x-ui.select>
            <div class="flex items-end">
                <x-ui.button type="submit" variant="secondary" class="w-full justify-center">
                    {{ __('operations.reservations.filter') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>

    @can('inventory.manage')
        <x-ui.card class="mt-5">
            <x-slot:header>
                <div>
                    <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.reservations.create') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('operations.reservations.create_help') }}</p>
                </div>
            </x-slot:header>

            <form method="POST" action="{{ route('inventory.reservations.store') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
                @csrf
                <x-ui.select name="location_id" :label="__('operations.reservations.location')" required>
                    @foreach($locations as $location)
                        <option value="{{ $location->id }}">
                            {{ $location->warehouse?->code }} · {{ $location->code }} — {{ $location->name }}
                        </option>
                    @endforeach
                </x-ui.select>

                <x-ui.select name="product_id" :label="__('operations.reservations.product')" required>
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

                <x-ui.input name="quantity" type="number" min="0.0001" step="0.0001" :label="__('operations.reservations.quantity')" required />
                <x-ui.input name="expires_at" type="datetime-local" :label="__('operations.reservations.expires_at')" />
                <x-ui.input name="note" :label="__('operations.reservations.note')" />

                <div class="md:col-span-2 xl:col-span-6 flex justify-end">
                    <x-ui.button type="submit" icon="lock-closed">{{ __('operations.reservations.reserve') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endcan

    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card :title="__('operations.reservations.active_count')" :value="$reservations->where('status', 'active')->count()" icon="lock-closed" tone="brand" />
        <x-ui.stat-card :title="__('operations.reservations.released_count')" :value="$reservations->where('status', 'released')->count()" icon="lock-open" tone="neutral" />
        <x-ui.stat-card :title="__('operations.reservations.consumed_count')" :value="$reservations->where('status', 'consumed')->count()" icon="check-circle" tone="success" />
        <x-ui.stat-card :title="__('operations.reservations.expired_count')" :value="$reservations->where('status', 'expired')->count()" icon="clock" tone="warning" />
    </div>

    <x-ui.card class="mt-5">
        <x-slot:header>
            <div>
                <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.reservations.history') }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ __('operations.reservations.history_help') }}</p>
            </div>
        </x-slot:header>

        <div class="overflow-x-auto">
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('operations.reservations.location') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reservations.product') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reservations.quantity') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reservations.on_hand') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reservations.reserved') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reservations.available') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reservations.status') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reservations.expires_at') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reservations.created_by') }}</x-ui.th>
                        <x-ui.th></x-ui.th>
                    </tr>
                </x-slot:head>

                @forelse($reservations as $reservation)
                    <tr>
                        <x-ui.td>
                            {{ $reservation->warehouse?->code }} · {{ $reservation->location?->code }}
                            <div class="text-xs text-slate-500">{{ $reservation->location?->name }}</div>
                        </x-ui.td>
                        <x-ui.td>
                            {{ $reservation->product?->name }}
                            @if($reservation->variant)<div class="text-xs text-slate-500">{{ $reservation->variant->name }}</div>@endif
                            @if($reservation->note)<div class="mt-1 text-xs text-slate-500">{{ $reservation->note }}</div>@endif
                        </x-ui.td>
                        <x-ui.td>{{ $reservation->quantity }}</x-ui.td>
                        <x-ui.td>{{ $reservation->on_hand_quantity }}</x-ui.td>
                        <x-ui.td>{{ $reservation->reserved_quantity }}</x-ui.td>
                        <x-ui.td>{{ $reservation->available_quantity }}</x-ui.td>
                        <x-ui.td>
                            <x-ui.badge :tone="$reservation->status === 'active' ? 'brand' : ($reservation->status === 'consumed' ? 'success' : ($reservation->status === 'expired' ? 'warning' : 'neutral'))">
                                {{ __('operations.reservations.status_'.$reservation->status) }}
                            </x-ui.badge>
                        </x-ui.td>
                        <x-ui.td>{{ $reservation->expires_at?->format('Y-m-d H:i') ?? '—' }}</x-ui.td>
                        <x-ui.td>{{ $reservation->creator?->name ?? '—' }}</x-ui.td>
                        <x-ui.td>
                            @can('inventory.manage')
                                @if($reservation->status === 'active')
                                    <form method="POST" action="{{ route('inventory.reservations.release', $reservation) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm" variant="secondary">
                                            {{ __('operations.reservations.release') }}
                                        </x-ui.button>
                                    </form>
                                @endif
                            @endcan
                        </x-ui.td>
                    </tr>
                @empty
                    <tr><x-ui.td colspan="10">{{ __('operations.reservations.empty') }}</x-ui.td></tr>
                @endforelse
            </x-ui.table>
        </div>
    </x-ui.card>
</x-app.page>
@endsection
