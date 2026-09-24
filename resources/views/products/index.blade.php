@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('products.title')"
        :subtitle="__('products.subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('products.title')],
        ]"
    >
        <x-slot:actions>
            <x-ui.button href="{{ route('products.export', request()->only('search', 'type')) }}" variant="secondary">
                {{ __('exports.export_csv') }}
            </x-ui.button>
            @can('products.manage')
                <x-ui.button href="{{ route('products.import') }}" variant="secondary" icon="document-text">{{ __('imports.import_products') }}</x-ui.button>
                <x-ui.button href="{{ route('products.create') }}" icon="plus">{{ __('products.add') }}</x-ui.button>
            @endcan
        </x-slot:actions>

        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        @cannot('products.manage')
            <div class="mb-6">
                <x-ui.alert type="info">{{ __('products.view_only') }}</x-ui.alert>
            </div>
        @endcannot

        <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <form method="GET" action="{{ route('products.index') }}" class="lg:max-w-md lg:flex-1" role="search">
                <label for="product-search" class="sr-only">{{ __('products.search') }}</label>
                <div class="relative max-w-md">
                    <x-ui.icon
                        name="search"
                        class="pointer-events-none absolute inset-y-0 start-0 my-auto ms-3 size-4 text-gray-400 dark:text-gray-500"
                        aria-hidden="true"
                    />
                    <input
                        id="product-search"
                        type="search"
                        name="search"
                        value="{{ $searchTerm }}"
                        placeholder="{{ __('products.search_placeholder') }}"
                        class="block w-full rounded-lg border border-gray-300 bg-white py-2 pe-16 ps-10 text-sm text-gray-900 shadow-sm
                            placeholder:text-gray-400 transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30
                            dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-brand-400"
                    />
                    @if ($searchTerm !== '')
                        <a
                            href="{{ route('products.index') }}"
                            class="absolute inset-y-0 end-0 flex items-center pe-2.5 text-sm font-medium text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300"
                        >
                            {{ __('actions.clear') }}
                        </a>
                    @endif
                </div>
                <input type="submit" value="{{ __('actions.search') }}" class="sr-only" />
            </form>

            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('products.type_filter') }}">
                <a
                    href="{{ route('products.index', $searchTerm !== '' ? ['search' => $searchTerm] : []) }}"
                    class="rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors duration-150 {{ $typeFilter === null ? 'bg-brand-600 text-white shadow-sm hover:bg-brand-700 dark:bg-brand-500 dark:hover:bg-brand-400' : 'border border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700' }}"
                >
                    {{ __('products.types.all') }}
                </a>
                @foreach (\App\Enums\ProductType::cases() as $case)
                    <a
                        href="{{ route('products.index', array_filter([
                            'search' => $searchTerm !== '' ? $searchTerm : null,
                            'type' => $case->value,
                        ], fn ($v) => $v !== null)) }}"
                        class="rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors duration-150 {{ $typeFilter === $case->value ? 'bg-brand-600 text-white shadow-sm hover:bg-brand-700 dark:bg-brand-500 dark:hover:bg-brand-400' : 'border border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700' }}"
                    >
                        {{ __('products.types.'.$case->value) }}
                    </a>
                @endforeach
            </div>
        </div>

        @if ($products->isEmpty())
            <x-ui.card>
                <x-ui.empty-state
                    :title="$searchTerm !== '' || $typeFilter !== null ? __('products.no_results') : __('products.no_products')"
                    :description="$searchTerm !== '' || $typeFilter !== null ? __('products.no_results_description') : __('products.no_products_description')"
                    icon="banknotes"
                >
                    @can('products.manage')
                        <x-slot:actions>
                            <x-ui.button href="{{ route('products.create') }}" icon="plus">{{ __('products.add') }}</x-ui.button>
                        </x-slot:actions>
                    @endcan
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <x-ui.table :caption="__('products.table_caption')">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('products.columns.type') }}</x-ui.th>
                        <x-ui.th>{{ __('products.columns.name') }}</x-ui.th>
                        <x-ui.th>{{ __('products.columns.sku') }}</x-ui.th>
                        <x-ui.th>{{ __('products.columns.category') }}</x-ui.th>
                        <x-ui.th>{{ __('products.columns.unit') }}</x-ui.th>
                        <x-ui.th>{{ __('products.columns.price') }}</x-ui.th>
                        <x-ui.th class="text-end">
                            <span class="sr-only">{{ __('products.columns.actions') }}</span>
                        </x-ui.th>
                    </tr>
                </x-slot:head>

                @foreach ($products as $product)
                    <tr>
                        <x-ui.td>
                            <x-ui.badge :tone="$product->type === \App\Enums\ProductType::Product ? 'brand' : 'info'">
                                {{ __('products.types.'.$product->type->value) }}
                            </x-ui.badge>
                        </x-ui.td>
                        <x-ui.td>
                            @can('products.manage')
                                <a
                                    href="{{ route('products.edit', $product) }}"
                                    class="font-semibold text-gray-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                                >
                                    {{ $product->name }}
                                </a>
                            @else
                                <span class="font-semibold text-gray-900 dark:text-white">{{ $product->name }}</span>
                            @endcan
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap text-gray-500 dark:text-gray-400" dir="ltr">{{ $product->sku ?: '—' }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-gray-500 dark:text-gray-400">{{ $product->category?->name ?: '—' }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-gray-500 dark:text-gray-400">{{ $product->unit?->name ?: '—' }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap font-medium tabular-nums text-gray-900 dark:text-white">{{ $product->sale_price }}</span>
                        </x-ui.td>
                        <x-ui.td class="text-end">
                            <div class="inline-flex items-center gap-1">
                                @can('products.manage')
                                    <x-ui.icon-button
                                        variant="secondary"
                                        size="sm"
                                        icon="pencil-square"
                                        :label="__('actions.edit')"
                                        href="{{ route('products.edit', $product) }}"
                                    />
                                    <x-ui.icon-button
                                        variant="danger"
                                        size="sm"
                                        icon="trash"
                                        :label="__('actions.delete')"
                                        x-on:click="$dispatch('bos:open-modal', { id: 'delete-product-modal' }); $dispatch('bos:delete-product', { id: {{ $product->id }} })"
                                    />
                                @endcan
                            </div>
                        </x-ui.td>
                    </tr>
                @endforeach

                <x-slot:footer>
                    <div class="px-4 py-3">
                        <x-ui.pagination :paginator="$products" />
                    </div>
                </x-slot:footer>
            </x-ui.table>
        @endif
    </x-app.page>

    @can('products.manage')
        <x-ui.modal id="delete-product-modal" :title="__('products.delete_confirm_title', ['name' => __('products.title')])" size="sm">
            <div x-data="deleteProductDialog">
                <template x-if="productId">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('products.delete_confirm') }}</p>

                        <form
                            class="mt-6 flex flex-wrap items-center justify-end gap-3"
                            method="POST"
                            x-bind:action="`/products/${productId}`"
                        >
                            @csrf
                            @method('DELETE')
                            <button
                                type="button"
                                x-on:click="close"
                                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm transition-colors duration-150 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                            >
                                {{ __('products.delete_cancel') }}
                            </button>
                            <x-ui.button type="submit" variant="danger" icon="trash">{{ __('products.delete_submit') }}</x-ui.button>
                        </form>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    @endcan
@endsection
