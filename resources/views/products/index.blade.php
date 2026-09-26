@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('products.title')"
        :subtitle="__('products.subtitle')"
        icon="cube"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('products.title')],
        ]"
    >
        @if (session('status'))
            <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
        @endif

        @cannot('products.manage')
            <div class="mb-5"><x-ui.alert type="info">{{ __('products.view_only') }}</x-ui.alert></div>
        @endcannot

        <x-ui.list-panel>
            <x-slot:actions>
                @can('products.manage')
                    <x-ui.button href="{{ route('products.create') }}" variant="outline" icon="plus">{{ __('products.add') }}</x-ui.button>
                    <x-ui.button href="{{ route('products.import') }}" variant="outline" icon="arrow-up-tray">{{ __('imports.import_products') }}</x-ui.button>
                @endcan
                <x-ui.button href="{{ route('products.export', request()->only('search', 'type')) }}" icon="arrow-down-tray">{{ __('exports.export_csv') }}</x-ui.button>
            </x-slot:actions>

            <x-slot:filters>
                <form method="GET" action="{{ route('products.index') }}" class="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(0,1.5fr)_minmax(180px,.7fr)_auto]" role="search">
                    <x-ui.search-input name="search" :label="__('products.search')" :placeholder="__('products.search_placeholder')" :value="$searchTerm" />

                    <x-ui.select name="type" :label="__('products.type_filter')" :value="$typeFilter">
                        <option value="">{{ __('products.types.all') }}</option>
                        @foreach (\App\Enums\ProductType::cases() as $case)
                            <option value="{{ $case->value }}" @selected($typeFilter === $case->value)>{{ __('products.types.'.$case->value) }}</option>
                        @endforeach
                    </x-ui.select>

                    <div class="flex items-end gap-2">
                        <x-ui.button type="submit" icon="search">{{ __('actions.search') }}</x-ui.button>
                        @if ($searchTerm !== '' || $typeFilter !== null)
                            <x-ui.button variant="secondary" href="{{ route('products.index') }}">{{ __('actions.clear') }}</x-ui.button>
                        @endif
                    </div>
                </form>
            </x-slot:filters>

            @if ($products->isEmpty())
                <x-ui.empty-state
                    :title="$searchTerm !== '' || $typeFilter !== null ? __('products.no_results') : __('products.no_products')"
                    :description="$searchTerm !== '' || $typeFilter !== null ? __('products.no_results_description') : __('products.no_products_description')"
                    icon="cube"
                >
                    @can('products.manage')
                        <x-slot:actions><x-ui.button href="{{ route('products.create') }}" icon="plus">{{ __('products.add') }}</x-ui.button></x-slot:actions>
                    @endcan
                </x-ui.empty-state>
            @else
                <x-ui.table :caption="__('products.table_caption')" class="shadow-none">
                    <x-slot:head>
                        <tr>
                            <x-ui.th class="w-12 text-center">#</x-ui.th>
                            <x-ui.th>{{ __('products.columns.type') }}</x-ui.th>
                            <x-ui.th>{{ __('products.columns.name') }}</x-ui.th>
                            <x-ui.th>{{ __('products.columns.sku') }}</x-ui.th>
                            <x-ui.th>{{ __('products.columns.category') }}</x-ui.th>
                            <x-ui.th>{{ __('products.columns.unit') }}</x-ui.th>
                            <x-ui.th numeric>{{ __('products.columns.price') }}</x-ui.th>
                            <x-ui.th class="w-16 text-center"><span class="sr-only">{{ __('products.columns.actions') }}</span></x-ui.th>
                        </tr>
                    </x-slot:head>

                    @foreach ($products as $product)
                        <tr>
                            <x-ui.td class="text-center text-slate-500">{{ ($products->firstItem() ?? 1) + $loop->index }}</x-ui.td>
                            <x-ui.td>
                                <x-ui.badge :tone="$product->type === \App\Enums\ProductType::Product ? 'brand' : 'info'">
                                    {{ __('products.types.'.$product->type->value) }}
                                </x-ui.badge>
                            </x-ui.td>
                            <x-ui.td>
                                @can('products.manage')
                                    <a href="{{ route('products.edit', $product) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">{{ $product->name }}</a>
                                @else
                                    <span class="font-semibold text-slate-900 dark:text-white">{{ $product->name }}</span>
                                @endcan
                            </x-ui.td>
                            <x-ui.td><span class="whitespace-nowrap text-slate-500 dark:text-slate-400" dir="ltr">{{ $product->sku ?: '—' }}</span></x-ui.td>
                            <x-ui.td><span class="text-slate-500 dark:text-slate-400">{{ $product->category?->name ?: '—' }}</span></x-ui.td>
                            <x-ui.td><span class="text-slate-500 dark:text-slate-400">{{ $product->unit?->name ?: '—' }}</span></x-ui.td>
                            <x-ui.td numeric><span class="whitespace-nowrap font-semibold">{{ number_format((float) $product->sale_price, 4) }}</span></x-ui.td>
                            <x-ui.td class="text-center">
                                @can('products.manage')
                                    <x-ui.dropdown align="end" width="w-40" :chevron="false" label="{{ __('products.columns.actions') }}">
                                        <x-slot:trigger><span class="grid size-8 place-items-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"><x-ui.icon name="ellipsis-horizontal" class="size-5" /></span></x-slot:trigger>
                                        <x-slot:items>
                                            <x-ui.dropdown-item :href="route('products.edit', $product)" icon="pencil-square">{{ __('actions.edit') }}</x-ui.dropdown-item>
                                            @if($product->type === \App\Enums\ProductType::Product)
                                                <x-ui.dropdown-item :href="route('products.variants.index', $product)" icon="squares-2x2">
                                                    {{ __('products.variants.manage') }} ({{ $product->variants_count }})
                                                </x-ui.dropdown-item>
                                            @endif
                                            <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10" x-on:click="close(); $dispatch('bos:open-modal', { id: 'delete-product-modal' }); $dispatch('bos:delete-product', { id: {{ $product->id }} })"><x-ui.icon name="trash" class="size-4" />{{ __('actions.delete') }}</button>
                                        </x-slot:items>
                                    </x-ui.dropdown>
                                @endcan
                            </x-ui.td>
                        </tr>
                    @endforeach

                    <x-slot:footer><div class="px-4 py-3"><x-ui.pagination :paginator="$products" /></div></x-slot:footer>
                </x-ui.table>
            @endif
        </x-ui.list-panel>
    </x-app.page>

    @can('products.manage')
        <x-ui.modal id="delete-product-modal" :title="__('products.delete_confirm_title', ['name' => __('products.title')])" size="sm">
            <div x-data="deleteProductDialog">
                <template x-if="productId">
                    <div>
                        <p class="text-[12px] leading-5 text-slate-500 dark:text-slate-400">{{ __('products.delete_confirm') }}</p>
                        <form class="mt-6 flex flex-wrap items-center justify-end gap-2" method="POST" x-bind:action="`/products/${productId}`">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="button" variant="secondary" x-on:click="close">{{ __('products.delete_cancel') }}</x-ui.button>
                            <x-ui.button type="submit" variant="danger" icon="trash">{{ __('products.delete_submit') }}</x-ui.button>
                        </form>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    @endcan
@endsection
