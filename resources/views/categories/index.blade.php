@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('categories.title')"
        :subtitle="__('categories.subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('categories.title')],
        ]"
    >
        <x-slot:actions>
            @can('categories.manage')
                <x-ui.button href="{{ route('categories.create') }}" icon="plus">{{ __('categories.add') }}</x-ui.button>
            @endcan
        </x-slot:actions>

        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        @cannot('categories.manage')
            <div class="mb-6">
                <x-ui.alert type="info">{{ __('categories.view_only') }}</x-ui.alert>
            </div>
        @endcannot

        <form method="GET" action="{{ route('categories.index') }}" class="mb-6" role="search">
            <label for="category-search" class="sr-only">{{ __('categories.search') }}</label>
            <div class="relative max-w-md">
                <x-ui.icon
                    name="search"
                    class="pointer-events-none absolute inset-y-0 start-0 my-auto ms-3 size-4 text-gray-400 dark:text-gray-500"
                    aria-hidden="true"
                />
                <input
                    id="category-search"
                    type="search"
                    name="search"
                    value="{{ $searchTerm }}"
                    placeholder="{{ __('categories.search_placeholder') }}"
                    class="block w-full rounded-lg border border-gray-300 bg-white py-2 pe-16 ps-10 text-sm text-gray-900 shadow-sm
                        placeholder:text-gray-400 transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30
                        dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-brand-400"
                />
                @if ($searchTerm !== '')
                    <a
                        href="{{ route('categories.index') }}"
                        class="absolute inset-y-0 end-0 flex items-center pe-2.5 text-sm font-medium text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300"
                    >
                        {{ __('actions.clear') }}
                    </a>
                @endif
            </div>
            <input type="submit" value="{{ __('actions.search') }}" class="sr-only" />
        </form>

        @if ($categories->isEmpty())
            <x-ui.card>
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
            </x-ui.card>
        @else
            <x-ui.table :caption="__('categories.table_caption')">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('categories.columns.name') }}</x-ui.th>
                        <x-ui.th>{{ __('categories.columns.description') }}</x-ui.th>
                        <x-ui.th>{{ __('categories.columns.created') }}</x-ui.th>
                        <x-ui.th class="text-end">
                            <span class="sr-only">{{ __('categories.columns.actions') }}</span>
                        </x-ui.th>
                    </tr>
                </x-slot:head>

                @foreach ($categories as $category)
                    <tr>
                        <x-ui.td>
                            @can('categories.manage')
                                <a
                                    href="{{ route('categories.edit', $category) }}"
                                    class="font-semibold text-gray-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                                >
                                    {{ $category->name }}
                                </a>
                            @else
                                <span class="font-semibold text-gray-900 dark:text-white">{{ $category->name }}</span>
                            @endcan
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-gray-500 dark:text-gray-400">{{ $category->description ?: '—' }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $category->created_at?->format('Y-m-d') }}</span>
                        </x-ui.td>
                        <x-ui.td class="text-end">
                            <div class="inline-flex items-center gap-1">
                                @can('categories.manage')
                                    <x-ui.icon-button
                                        variant="secondary"
                                        size="sm"
                                        icon="pencil-square"
                                        :label="__('actions.edit')"
                                        href="{{ route('categories.edit', $category) }}"
                                    />
                                    <x-ui.icon-button
                                        variant="danger"
                                        size="sm"
                                        icon="trash"
                                        :label="__('actions.delete')"
                                        x-on:click="$dispatch('bos:open-modal', { id: 'delete-category-modal' }); $dispatch('bos:delete-category', { id: {{ $category->id }} })"
                                    />
                                @endcan
                            </div>
                        </x-ui.td>
                    </tr>
                @endforeach

                <x-slot:footer>
                    <div class="px-4 py-3">
                        <x-ui.pagination :paginator="$categories" />
                    </div>
                </x-slot:footer>
            </x-ui.table>
        @endif
    </x-app.page>

    @can('categories.manage')
        <x-ui.modal id="delete-category-modal" :title="__('categories.delete_confirm_title', ['name' => __('categories.title')])" size="sm">
            <div x-data="deleteCategoryDialog">
                <template x-if="categoryId">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('categories.delete_confirm') }}</p>

                        <form
                            class="mt-6 flex flex-wrap items-center justify-end gap-3"
                            method="POST"
                            x-bind:action="`/categories/${categoryId}`"
                        >
                            @csrf
                            @method('DELETE')
                            <button
                                type="button"
                                x-on:click="close"
                                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm transition-colors duration-150 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                            >
                                {{ __('categories.delete_cancel') }}
                            </button>
                            <x-ui.button type="submit" variant="danger" icon="trash">{{ __('categories.delete_submit') }}</x-ui.button>
                        </form>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    @endcan
@endsection