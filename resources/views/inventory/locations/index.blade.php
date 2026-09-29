@extends('layouts.app')

@section('content')
<x-app.page icon="map-pin" :title="__('operations.locations.title')" :subtitle="__('operations.locations.subtitle')">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="{{ route('inventory.warehouses.index') }}" variant="secondary">{{ __('operations.warehouses.title') }}</x-ui.button>
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

    <x-ui.card>
        <form method="GET" action="{{ route('inventory.locations.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="min-w-64 flex-1">
                <x-ui.select name="warehouse_id" :label="__('operations.locations.warehouse')">
                    <option value="">{{ __('operations.locations.all_warehouses') }}</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}" @selected($selectedWarehouseId === $warehouse->id)>
                            {{ $warehouse->code }} — {{ $warehouse->name }}
                        </option>
                    @endforeach
                </x-ui.select>
            </div>
            <x-ui.button type="submit" variant="secondary">{{ __('operations.locations.filter') }}</x-ui.button>
        </form>
    </x-ui.card>

    @can('inventory.manage')
        <x-ui.card class="mt-5">
            <x-slot:header>
                <div>
                    <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.locations.add') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('operations.locations.add_help') }}</p>
                </div>
            </x-slot:header>

            <form method="POST" action="{{ route('inventory.locations.store') }}" class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
                @csrf
                <x-ui.select name="warehouse_id" :label="__('operations.locations.warehouse')" required>
                    @foreach($warehouses->where('is_active', true) as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.select name="parent_id" :label="__('operations.locations.parent')">
                    <option value="">{{ __('operations.locations.no_parent') }}</option>
                    @foreach($locations->where('is_active', true) as $location)
                        <option value="{{ $location->id }}">
                            {{ $location->warehouse?->code }} · {{ $location->code }} — {{ $location->name }}
                        </option>
                    @endforeach
                </x-ui.select>

                <x-ui.input name="code" :label="__('operations.locations.code')" required />
                <x-ui.input name="name" :label="__('operations.locations.name')" required />

                <x-ui.select name="type" :label="__('operations.locations.type')" required>
                    @foreach($types as $type)
                        <option value="{{ $type }}">{{ __('operations.locations.types.'.$type) }}</option>
                    @endforeach
                </x-ui.select>

                <div class="flex items-end">
                    <label class="flex w-full items-center gap-2 rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-700 dark:border-slate-700 dark:text-slate-200">
                        <input type="hidden" name="is_default" value="0">
                        <input type="checkbox" name="is_default" value="1" class="rounded border-slate-300">
                        {{ __('operations.locations.make_default') }}
                    </label>
                </div>

                <div class="md:col-span-2 xl:col-span-6 flex justify-end">
                    <x-ui.button type="submit" icon="plus">{{ __('operations.locations.add') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endcan

    <div class="mt-5 space-y-4">
        @forelse($locations as $location)
            <x-ui.card>
                <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <form method="POST" action="{{ route('inventory.locations.update', $location) }}" class="grid flex-1 gap-3 md:grid-cols-2 xl:grid-cols-5">
                        @csrf
                        @method('PUT')

                        <x-ui.input name="code" :label="__('operations.locations.code')" :value="$location->code" required />
                        <x-ui.input name="name" :label="__('operations.locations.name')" :value="$location->name" required />

                        <x-ui.select name="type" :label="__('operations.locations.type')" required>
                            @foreach($types as $type)
                                <option value="{{ $type }}" @selected($location->type === $type)>
                                    {{ __('operations.locations.types.'.$type) }}
                                </option>
                            @endforeach
                        </x-ui.select>

                        <x-ui.select name="parent_id" :label="__('operations.locations.parent')">
                            <option value="">{{ __('operations.locations.no_parent') }}</option>
                            @foreach($locations->where('warehouse_id', $location->warehouse_id)->where('id', '!=', $location->id) as $candidate)
                                <option value="{{ $candidate->id }}" @selected($location->parent_id === $candidate->id)>
                                    {{ $candidate->code }} — {{ $candidate->name }}
                                </option>
                            @endforeach
                        </x-ui.select>

                        @can('inventory.manage')
                            <div class="flex items-end">
                                <x-ui.button type="submit" size="sm" variant="secondary">{{ __('operations.locations.save') }}</x-ui.button>
                            </div>
                        @endcan
                    </form>

                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.badge tone="neutral">{{ $location->warehouse?->code }}</x-ui.badge>
                        @if($location->is_default)
                            <x-ui.badge tone="brand" dot>{{ __('operations.locations.default') }}</x-ui.badge>
                        @endif
                        <x-ui.badge :tone="$location->is_active ? 'success' : 'neutral'" dot>
                            {{ $location->is_active ? __('operations.locations.active') : __('operations.locations.inactive') }}
                        </x-ui.badge>

                        @can('inventory.manage')
                            @unless($location->is_default)
                                @if($location->is_active)
                                    <form method="POST" action="{{ route('inventory.locations.default', $location) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm">{{ __('operations.locations.set_default') }}</x-ui.button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('inventory.locations.active', $location) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $location->is_active ? 0 : 1 }}">
                                    <x-ui.button type="submit" size="sm" variant="secondary">
                                        {{ $location->is_active ? __('operations.locations.deactivate') : __('operations.locations.activate') }}
                                    </x-ui.button>
                                </form>

                                <form method="POST" action="{{ route('inventory.locations.destroy', $location) }}" onsubmit="return confirm('{{ __('operations.locations.delete_confirm') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" size="sm" variant="danger">{{ __('operations.locations.delete') }}</x-ui.button>
                                </form>
                            @endunless
                        @endcan
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card>{{ __('operations.locations.empty') }}</x-ui.card>
        @endforelse
    </div>

    <x-ui.card class="mt-5">
        <x-slot:header>
            <div>
                <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.locations.stock_title') }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ __('operations.locations.stock_help') }}</p>
            </div>
        </x-slot:header>

        <div class="overflow-x-auto">
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('operations.locations.warehouse') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.locations.location') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.locations.product') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.locations.variant') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.locations.quantity') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reservations.reserved') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reservations.available') }}</x-ui.th>
                    </tr>
                </x-slot:head>
                @forelse($balances as $balance)
                    <tr>
                        <x-ui.td>{{ $balance->location?->warehouse?->name ?? '—' }}</x-ui.td>
                        <x-ui.td>{{ $balance->location?->code }} — {{ $balance->location?->name }}</x-ui.td>
                        <x-ui.td>{{ $balance->product?->name ?? '—' }}</x-ui.td>
                        <x-ui.td>{{ $balance->variant?->name ?? '—' }}</x-ui.td>
                        <x-ui.td>{{ $balance->quantity }}</x-ui.td>
                        <x-ui.td>{{ $balance->reserved_quantity }}</x-ui.td>
                        <x-ui.td>{{ $balance->available_quantity }}</x-ui.td>
                    </tr>
                @empty
                    <tr><x-ui.td colspan="7">{{ __('operations.locations.no_stock') }}</x-ui.td></tr>
                @endforelse
            </x-ui.table>
        </div>
    </x-ui.card>
</x-app.page>
@endsection
