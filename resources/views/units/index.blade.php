@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('units.title')"
        :subtitle="__('units.subtitle')"
        icon="cube"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('units.title')],
        ]"
    >
        @if (session('status'))
            <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
        @endif

        @cannot('units.manage')
            <div class="mb-5"><x-ui.alert type="info">{{ __('units.view_only') }}</x-ui.alert></div>
        @endcannot

        <x-ui.list-panel>
            <x-slot:actions>
                @can('units.manage')
                    <x-ui.button href="{{ route('units.create') }}" variant="outline" icon="plus">{{ __('units.add') }}</x-ui.button>
                @endcan
            </x-slot:actions>

            <x-slot:filters>
                <form method="GET" action="{{ route('units.index') }}" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto]" role="search">
                    <x-ui.search-input name="search" :label="__('units.search')" :placeholder="__('units.search_placeholder')" :value="$searchTerm" />
                    <div class="flex items-end gap-2">
                        <x-ui.button type="submit" icon="search">{{ __('actions.search') }}</x-ui.button>
                        @if ($searchTerm !== '')<x-ui.button variant="secondary" href="{{ route('units.index') }}">{{ __('actions.clear') }}</x-ui.button>@endif
                    </div>
                </form>
            </x-slot:filters>

            @if ($units->isEmpty())
                <x-ui.empty-state
                    :title="$searchTerm !== '' ? __('units.no_results') : __('units.no_units')"
                    :description="$searchTerm !== '' ? __('units.no_results_description') : __('units.no_units_description')"
                    icon="cube"
                >
                    @can('units.manage')
                        <x-slot:actions><x-ui.button href="{{ route('units.create') }}" icon="plus">{{ __('units.add') }}</x-ui.button></x-slot:actions>
                    @endcan
                </x-ui.empty-state>
            @else
                <x-ui.table :caption="__('units.table_caption')" class="shadow-none">
                    <x-slot:head>
                        <tr>
                            <x-ui.th class="w-12 text-center">#</x-ui.th>
                            <x-ui.th>{{ __('units.columns.name') }}</x-ui.th>
                            <x-ui.th>{{ __('units.columns.short_name') }}</x-ui.th>
                            <x-ui.th>{{ __('units.columns.created') }}</x-ui.th>
                            <x-ui.th class="w-16 text-center"><span class="sr-only">{{ __('units.columns.actions') }}</span></x-ui.th>
                        </tr>
                    </x-slot:head>

                    @foreach ($units as $unit)
                        <tr>
                            <x-ui.td class="text-center text-slate-500">{{ ($units->firstItem() ?? 1) + $loop->index }}</x-ui.td>
                            <x-ui.td>
                                @can('units.manage')
                                    <a href="{{ route('units.edit', $unit) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">{{ $unit->name }}</a>
                                @else
                                    <span class="font-semibold text-slate-900 dark:text-white">{{ $unit->name }}</span>
                                @endcan
                            </x-ui.td>
                            <x-ui.td><span class="text-slate-500 dark:text-slate-400">{{ $unit->short_name ?: '—' }}</span></x-ui.td>
                            <x-ui.td><span class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $unit->created_at?->format('Y-m-d') }}</span></x-ui.td>
                            <x-ui.td class="text-center">
                                @can('units.manage')
                                    <x-ui.dropdown align="end" width="w-40" :chevron="false" label="{{ __('units.columns.actions') }}">
                                        <x-slot:trigger><span class="grid size-8 place-items-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"><x-ui.icon name="ellipsis-horizontal" class="size-5" /></span></x-slot:trigger>
                                        <x-slot:items>
                                            <x-ui.dropdown-item :href="route('units.edit', $unit)" icon="pencil-square">{{ __('actions.edit') }}</x-ui.dropdown-item>
                                            <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10" x-on:click="close(); $dispatch('bos:open-modal', { id: 'delete-unit-modal' }); $dispatch('bos:delete-unit', { id: {{ $unit->id }} })"><x-ui.icon name="trash" class="size-4" />{{ __('actions.delete') }}</button>
                                        </x-slot:items>
                                    </x-ui.dropdown>
                                @endcan
                            </x-ui.td>
                        </tr>
                    @endforeach

                    <x-slot:footer><div class="px-4 py-3"><x-ui.pagination :paginator="$units" /></div></x-slot:footer>
                </x-ui.table>
            @endif
        </x-ui.list-panel>
    </x-app.page>

    @can('units.manage')
        <x-ui.modal id="delete-unit-modal" :title="__('units.delete_confirm_title', ['name' => __('units.title')])" size="sm">
            <div x-data="deleteUnitDialog">
                <template x-if="unitId">
                    <div>
                        <p class="text-[12px] leading-5 text-slate-500 dark:text-slate-400">{{ __('units.delete_confirm') }}</p>
                        <form class="mt-6 flex flex-wrap items-center justify-end gap-2" method="POST" x-bind:action="`/units/${unitId}`">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="button" variant="secondary" x-on:click="close">{{ __('units.delete_cancel') }}</x-ui.button>
                            <x-ui.button type="submit" variant="danger" icon="trash">{{ __('units.delete_submit') }}</x-ui.button>
                        </form>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    @endcan
@endsection
