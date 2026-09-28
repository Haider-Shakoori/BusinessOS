@extends('layouts.app')

@section('content')
<x-app.page icon="building-office-2" :title="__('operations.warehouses.title')" :subtitle="__('operations.warehouses.subtitle')">
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
                <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.warehouses.add') }}</h2>
            </x-slot:header>

            <form method="POST" action="{{ route('inventory.warehouses.store') }}" class="grid gap-4 md:grid-cols-4">
                @csrf
                <x-ui.input name="code" :label="__('operations.warehouses.code')" required />
                <div class="md:col-span-2">
                    <x-ui.input name="name" :label="__('operations.warehouses.name')" required />
                </div>
                <div class="flex items-end">
                    <label class="flex w-full items-center gap-2 rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-700 dark:border-slate-700 dark:text-slate-200">
                        <input type="hidden" name="is_default" value="0">
                        <input type="checkbox" name="is_default" value="1" class="rounded border-slate-300">
                        {{ __('operations.warehouses.make_default') }}
                    </label>
                </div>
                <div class="md:col-span-4 flex justify-end">
                    <x-ui.button type="submit" icon="plus">{{ __('operations.warehouses.add') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endcan

    <div class="mt-5 space-y-4">
        @forelse($warehouses as $warehouse)
            <x-ui.card>
                <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <form method="POST" action="{{ route('inventory.warehouses.update', $warehouse) }}" class="grid flex-1 gap-3 sm:grid-cols-2 lg:grid-cols-[180px_minmax(260px,1fr)_auto]">
                        @csrf
                        @method('PUT')
                        <x-ui.input name="code" :label="__('operations.warehouses.code')" :value="$warehouse->code" required />
                        <x-ui.input name="name" :label="__('operations.warehouses.name')" :value="$warehouse->name" required />
                        @can('inventory.manage')
                            <div class="flex items-end">
                                <x-ui.button type="submit" size="sm" variant="secondary">{{ __('operations.warehouses.save') }}</x-ui.button>
                            </div>
                        @endcan
                    </form>

                    <div class="flex flex-wrap items-center gap-2">
                        @if($warehouse->is_default)
                            <x-ui.badge tone="brand" dot>{{ __('operations.warehouses.default') }}</x-ui.badge>
                        @endif
                        <x-ui.badge :tone="$warehouse->is_active ? 'success' : 'neutral'" dot>
                            {{ $warehouse->is_active ? __('operations.warehouses.active') : __('operations.warehouses.inactive') }}
                        </x-ui.badge>

                        @can('inventory.manage')
                            @unless($warehouse->is_default)
                                @if($warehouse->is_active)
                                    <form method="POST" action="{{ route('inventory.warehouses.default', $warehouse) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm">{{ __('operations.warehouses.set_default') }}</x-ui.button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('inventory.warehouses.active', $warehouse) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="is_active" value="{{ $warehouse->is_active ? 0 : 1 }}">
                                    <x-ui.button type="submit" size="sm" variant="secondary">
                                        {{ $warehouse->is_active ? __('operations.warehouses.deactivate') : __('operations.warehouses.activate') }}
                                    </x-ui.button>
                                </form>

                                <form method="POST" action="{{ route('inventory.warehouses.destroy', $warehouse) }}" onsubmit="return confirm('{{ __('operations.warehouses.delete_confirm') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <x-ui.button type="submit" size="sm" variant="danger">{{ __('operations.warehouses.delete') }}</x-ui.button>
                                </form>
                            @endunless
                        @endcan
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card>{{ __('operations.warehouses.no_data') }}</x-ui.card>
        @endforelse
    </div>
</x-app.page>
@endsection
