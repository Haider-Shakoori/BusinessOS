@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('units.title')"
        :subtitle="__('units.subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('units.title')],
        ]"
    >
        <x-slot:actions>
            @can('units.manage')
                <x-ui.button href="{{ route('units.create') }}" icon="plus">{{ __('units.add') }}</x-ui.button>
            @endcan
        </x-slot:actions>

        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        @cannot('units.manage')
            <div class="mb-6">
                <x-ui.alert type="info">{{ __('units.view_only') }}</x-ui.alert>
            </div>
        @endcannot

        <form method="GET" action="{{ route('units.index') }}" class="mb-6" role="search">
            <label for="unit-search" class="sr-only">{{ __('units.search') }}</label>
            <div class="relative max-w-md">
                <x-ui.icon
                    name="search"
                    class="pointer-events-none absolute inset-y-0 start-0 my-auto ms-3 size-4 text-gray-400 dark:text-gray-500"
                    aria-hidden="true"
                />
                <input
                    id="unit-search"
                    type="search"
                    name="search"
                    value="{{ $searchTerm }}"
                    placeholder="{{ __('units.search_placeholder') }}"
                    class="block w-full rounded-lg border border-gray-300 bg-white py-2 pe-16 ps-10 text-sm text-gray-900 shadow-sm
                        placeholder:text-gray-400 transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30
                        dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-brand-400"
                />
                @if ($searchTerm !== '')
                    <a
                        href="{{ route('units.index') }}"
                        class="absolute inset-y-0 end-0 flex items-center pe-2.5 text-sm font-medium text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300"
                    >
                        {{ __('actions.clear') }}
                    </a>
                @endif
            </div>
            <input type="submit" value="{{ __('actions.search') }}" class="sr-only" />
        </form>

        @if ($units->isEmpty())
            <x-ui.card>
                <x-ui.empty-state
                    :title="$searchTerm !== '' ? __('units.no_results') : __('units.no_units')"
                    :description="$searchTerm !== '' ? __('units.no_results_description') : __('units.no_units_description')"
                    icon="cube"
                >
                    @can('units.manage')
                        <x-slot:actions>
                            <x-ui.button href="{{ route('units.create') }}" icon="plus">{{ __('units.add') }}</x-ui.button>
                        </x-slot:actions>
                    @endcan
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <x-ui.table :caption="__('units.table_caption')">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('units.columns.name') }}</x-ui.th>
                        <x-ui.th>{{ __('units.columns.short_name') }}</x-ui.th>
                        <x-ui.th>{{ __('units.columns.created') }}</x-ui.th>
                        <x-ui.th class="text-end">
                            <span class="sr-only">{{ __('units.columns.actions') }}</span>
                        </x-ui.th>
                    </tr>
                </x-slot:head>

                @foreach ($units as $unit)
                    <tr>
                        <x-ui.td>
                            @can('units.manage')
                                <a
                                    href="{{ route('units.edit', $unit) }}"
                                    class="font-semibold text-gray-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                                >
                                    {{ $unit->name }}
                                </a>
                            @else
                                <span class="font-semibold text-gray-900 dark:text-white">{{ $unit->name }}</span>
                            @endcan
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-gray-500 dark:text-gray-400">{{ $unit->short_name ?: '—' }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $unit->created_at?->format('Y-m-d') }}</span>
                        </x-ui.td>
                        <x-ui.td class="text-end">
                            <div class="inline-flex items-center gap-1">
                                @can('units.manage')
                                    <x-ui.icon-button
                                        variant="secondary"
                                        size="sm"
                                        icon="pencil-square"
                                        :label="__('actions.edit')"
                                        href="{{ route('units.edit', $unit) }}"
                                    />
                                    <x-ui.icon-button
                                        variant="danger"
                                        size="sm"
                                        icon="trash"
                                        :label="__('actions.delete')"
                                        x-on:click="$dispatch('bos:open-modal', { id: 'delete-unit-modal' }); $dispatch('bos:delete-unit', { id: {{ $unit->id }} })"
                                    />
                                @endcan
                            </div>
                        </x-ui.td>
                    </tr>
                @endforeach

                <x-slot:footer>
                    <div class="px-4 py-3">
                        <x-ui.pagination :paginator="$units" />
                    </div>
                </x-slot:footer>
            </x-ui.table>
        @endif
    </x-app.page>

    @can('units.manage')
        <x-ui.modal id="delete-unit-modal" :title="__('units.delete_confirm_title', ['name' => __('units.title')])" size="sm">
            <div x-data="deleteUnitDialog">
                <template x-if="unitId">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('units.delete_confirm') }}</p>

                        <form
                            class="mt-6 flex flex-wrap items-center justify-end gap-3"
                            method="POST"
                            x-bind:action="`/units/${unitId}`"
                        >
                            @csrf
                            @method('DELETE')
                            <button
                                type="button"
                                x-on:click="close"
                                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm transition-colors duration-150 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                            >
                                {{ __('units.delete_cancel') }}
                            </button>
                            <x-ui.button type="submit" variant="danger" icon="trash">{{ __('units.delete_submit') }}</x-ui.button>
                        </form>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    @endcan
@endsection