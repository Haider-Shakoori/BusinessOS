@extends('layouts.app')

@section('content')
<x-app.page icon="clipboard-document-check" :title="__('operations.stock_counts.title')" :subtitle="__('operations.stock_counts.subtitle')">
    <x-slot:actions>
        <x-ui.button href="{{ route('inventory.index') }}" variant="secondary">{{ __('operations.inventory.title') }}</x-ui.button>
    </x-slot:actions>

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif
    @if ($errors->any())
        <div class="mb-5"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div>
    @endif

    @can('inventory.manage')
        <x-ui.card>
            <x-slot:header>
                <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.stock_counts.new') }}</h2>
            </x-slot:header>

            <form method="POST" action="{{ route('inventory.counts.store') }}" class="grid gap-4 lg:grid-cols-5">
                @csrf
                <x-ui.select name="warehouse_id" :label="__('operations.stock_counts.warehouse')" required>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select name="location_id" :label="__('operations.stock_counts.location')">
                    <option value="">{{ __('operations.stock_counts.default_location') }}</option>
                    @foreach($locations as $location)
                        <option value="{{ $location->id }}">{{ $location->warehouse?->code }} · {{ $location->code }} — {{ $location->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="count_date" type="date" :value="old('count_date', now()->toDateString())" :label="__('operations.stock_counts.date')" required />
                <div class="lg:col-span-2">
                    <x-ui.input name="notes" :label="__('operations.stock_counts.notes')" />
                </div>
                <div class="lg:col-span-5 flex justify-end">
                    <x-ui.button type="submit" icon="plus">{{ __('operations.stock_counts.start') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endcan

    <x-ui.card class="mt-5">
        <x-slot:header>
            <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.stock_counts.history') }}</h2>
        </x-slot:header>

        <div class="overflow-x-auto">
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('operations.stock_counts.number') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.stock_counts.warehouse') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.stock_counts.location') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.stock_counts.date') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.stock_counts.items') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.stock_counts.status') }}</x-ui.th>
                        <x-ui.th></x-ui.th>
                    </tr>
                </x-slot:head>
                @forelse($counts as $count)
                    <tr>
                        <x-ui.td>{{ $count->number }}</x-ui.td>
                        <x-ui.td>{{ $count->warehouse?->name }}</x-ui.td>
                        <x-ui.td>{{ $count->location?->code }} — {{ $count->location?->name }}</x-ui.td>
                        <x-ui.td>{{ $count->count_date?->format('Y-m-d') }}</x-ui.td>
                        <x-ui.td>{{ $count->items_count }}</x-ui.td>
                        <x-ui.td>
                            <x-ui.badge tone="{{ $count->status === 'posted' ? 'success' : ($count->status === 'submitted' ? 'brand' : 'neutral') }}">
                                {{ __('operations.stock_counts.status_'.$count->status) }}
                            </x-ui.badge>
                        </x-ui.td>
                        <x-ui.td>
                            <x-ui.button href="{{ route('inventory.counts.show', $count) }}" size="sm" variant="secondary">
                                {{ __('operations.stock_counts.open') }}
                            </x-ui.button>
                        </x-ui.td>
                    </tr>
                @empty
                    <tr><x-ui.td colspan="7">{{ __('operations.stock_counts.empty_history') }}</x-ui.td></tr>
                @endforelse
            </x-ui.table>
        </div>
    </x-ui.card>
</x-app.page>
@endsection
