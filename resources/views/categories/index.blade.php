@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('categories.title')"
        :subtitle="__('categories.subtitle')"
        icon="tag"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('categories.title')],
        ]"
    >
        @if (session('status'))
            <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
        @endif

        @cannot('categories.manage')
            <div class="mb-5"><x-ui.alert type="info">{{ __('categories.view_only') }}</x-ui.alert></div>
        @endcannot

        <x-ui.list-panel>
            <x-slot:actions>
                @can('categories.manage')
                    <x-ui.button href="{{ route('categories.create') }}" variant="outline" icon="plus">{{ __('categories.add') }}</x-ui.button>
                @endcan
            </x-slot:actions>

            <x-slot:filters>
                <form method="GET" action="{{ route('categories.index') }}" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto]" role="search">
                    <x-ui.search-input
                        name="search"
                        :label="__('categories.search')"
                        :placeholder="__('categories.search_placeholder')"
                        :value="$searchTerm"
                    />
                    <div class="flex items-end gap-2">
                        <x-ui.button type="submit" icon="search">{{ __('actions.search') }}</x-ui.button>
                        @if ($searchTerm !== '')
                            <x-ui.button variant="secondary" href="{{ route('categories.index') }}">{{ __('actions.clear') }}</x-ui.button>
                        @endif
                    </div>
                </form>
            </x-slot:filters>

            @if ($categories->isEmpty())
                <x-ui.empty-state
                    :title="$searchTerm !== '' ? __('categories.no_results') : __('categories.no_categories')"
                    :description="$searchTerm !== '' ? __('categories.no_results_description') : __('categories.no_categories_description')"
                    icon="tag"
                >
                    @can('categories.manage')
                        <x-slot:actions>
                            <x-ui.button href="{{ route('categories.create') }}" icon="plus">{{ __('categories.add') }}</x-ui.button>
                        </x-slot:actions>
                    @endcan
                </x-ui.empty-state>
            @else
                <x-ui.table :caption="__('categories.table_caption')" class="shadow-none">
                    <x-slot:head>
                        <tr>
                            <x-ui.th class="w-12 text-center">#</x-ui.th>
                            <x-ui.th>{{ __('categories.columns.name') }}</x-ui.th>
                            <x-ui.th>{{ __('categories.columns.description') }}</x-ui.th>
                            <x-ui.th>{{ __('categories.columns.created') }}</x-ui.th>
                            <x-ui.th class="w-16 text-center"><span class="sr-only">{{ __('categories.columns.actions') }}</span></x-ui.th>
                        </tr>
                    </x-slot:head>

                    @foreach ($categories as $category)
                        <tr>
                            <x-ui.td class="text-center text-slate-500">{{ ($categories->firstItem() ?? 1) + $loop->index }}</x-ui.td>
                            <x-ui.td>
                                @can('categories.manage')
                                    <a href="{{ route('categories.edit', $category) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">{{ $category->name }}</a>
                                @else
                                    <span class="font-semibold text-slate-900 dark:text-white">{{ $category->name }}</span>
                                @endcan
                            </x-ui.td>
                            <x-ui.td><span class="text-slate-500 dark:text-slate-400">{{ $category->description ?: '—' }}</span></x-ui.td>
                            <x-ui.td><span class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $category->created_at?->format('Y-m-d') }}</span></x-ui.td>
                            <x-ui.td class="text-center">
                                @can('categories.manage')
                                    <x-ui.dropdown align="end" width="w-40" :chevron="false" label="{{ __('categories.columns.actions') }}">
                                        <x-slot:trigger>
                                            <span class="grid size-8 place-items-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white">
                                                <x-ui.icon name="ellipsis-horizontal" class="size-5" />
                                            </span>
                                        </x-slot:trigger>
                                        <x-slot:items>
                                            <x-ui.dropdown-item :href="route('categories.edit', $category)" icon="pencil-square">{{ __('actions.edit') }}</x-ui.dropdown-item>
                                            <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10" x-on:click="close(); $dispatch('bos:open-modal', { id: 'delete-category-modal' }); $dispatch('bos:delete-category', { id: {{ $category->id }} })">
                                                <x-ui.icon name="trash" class="size-4" />{{ __('actions.delete') }}
                                            </button>
                                        </x-slot:items>
                                    </x-ui.dropdown>
                                @endcan
                            </x-ui.td>
                        </tr>
                    @endforeach

                    <x-slot:footer><div class="px-4 py-3"><x-ui.pagination :paginator="$categories" /></div></x-slot:footer>
                </x-ui.table>
            @endif
        </x-ui.list-panel>
    </x-app.page>

    @can('categories.manage')
        <x-ui.modal id="delete-category-modal" :title="__('categories.delete_confirm_title', ['name' => __('categories.title')])" size="sm">
            <div x-data="deleteCategoryDialog">
                <template x-if="categoryId">
                    <div>
                        <p class="text-[12px] leading-5 text-slate-500 dark:text-slate-400">{{ __('categories.delete_confirm') }}</p>
                        <form class="mt-6 flex flex-wrap items-center justify-end gap-2" method="POST" x-bind:action="`/categories/${categoryId}`">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="button" variant="secondary" x-on:click="close">{{ __('categories.delete_cancel') }}</x-ui.button>
                            <x-ui.button type="submit" variant="danger" icon="trash">{{ __('categories.delete_submit') }}</x-ui.button>
                        </form>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    @endcan
@endsection
