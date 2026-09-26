@extends('layouts.app')

@section('content')
<x-app.page
    icon="squares-2x2"
    :title="__('products.variants.title')"
    :subtitle="__('products.variants.subtitle')"
    :breadcrumbs="[
        ['label' => __('modules.dashboard'), 'url' => route('app.home')],
        ['label' => __('products.title'), 'url' => route('products.index')],
        ['label' => $product->name],
        ['label' => __('products.variants.title')],
    ]"
>
    <x-slot:actions>
        @can('products.manage')
            <x-ui.button href="{{ route('products.edit', $product) }}" variant="secondary" icon="pencil-square">{{ __('actions.edit') }}</x-ui.button>
        @endcan
        <x-ui.button href="{{ route('products.index') }}" variant="secondary">{{ __('products.title') }}</x-ui.button>
    </x-slot:actions>

    @if(session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif
    @if($errors->any())
        <div class="mb-5"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div>
    @endif

    <div class="grid gap-5 xl:grid-cols-[minmax(0,420px)_1fr]">
        @can('products.manage')
            <x-ui.card>
                <x-slot:header>
                    <div>
                        <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('products.variants.add') }}</h2>
                        <p class="mt-1 text-xs text-slate-500">{{ $product->name }} · {{ number_format((float)$product->sale_price, 4) }}</p>
                    </div>
                </x-slot:header>
                <form method="POST" action="{{ route('products.variants.store', $product) }}" class="space-y-4">
                    @csrf
                    <x-ui.input name="name" :label="__('products.variants.name')" :placeholder="__('products.variants.name_placeholder')" required />
                    <x-ui.input name="sku" :label="__('products.variants.sku')" dir="ltr" maxlength="50" />
                    <x-ui.number-input name="sale_price" :label="__('products.variants.sale_price')" :helper="__('products.variants.price_helper')" min="0" step="0.0001" />
                    <x-ui.button type="submit" icon="plus" class="w-full justify-center">{{ __('products.variants.add') }}</x-ui.button>
                </form>
            </x-ui.card>
        @endcan

        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="font-semibold text-slate-900 dark:text-white">{{ $product->name }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ __('products.variants.count', ['count' => $variants->count()]) }}</p>
                </div>
            </x-slot:header>

            @if($variants->isEmpty())
                <x-ui.empty-state :title="__('products.variants.empty')" icon="squares-2x2" />
            @else
                <div class="space-y-3">
                    @foreach($variants as $variant)
                        <form method="POST" action="{{ route('products.variants.update', [$product, $variant]) }}" class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                            @csrf
                            @method('PATCH')
                            <div class="grid gap-3 md:grid-cols-[minmax(0,1.2fr)_minmax(0,.8fr)_minmax(0,.8fr)_auto] md:items-end">
                                <x-ui.input name="name" :label="__('products.variants.name')" :value="$variant->name" required />
                                <x-ui.input name="sku" :label="__('products.variants.sku')" :value="$variant->sku" dir="ltr" />
                                <x-ui.number-input name="sale_price" :label="__('products.variants.sale_price')" :value="$variant->sale_price" :placeholder="__('products.variants.inherit')" min="0" step="0.0001" />
                                <div class="flex items-center gap-2 pb-1">
                                    <input type="hidden" name="is_active" value="0">
                                    <label class="inline-flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200">
                                        <input type="checkbox" name="is_active" value="1" @checked($variant->is_active) class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-900">
                                        {{ __('products.variants.active') }}
                                    </label>
                                </div>
                            </div>
                            <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                                <div class="text-xs text-slate-500">
                                    {{ __('products.variants.effective_price') }}:
                                    <strong class="text-slate-700 dark:text-slate-200">{{ number_format((float)$variant->effectiveSalePrice(), 4) }}</strong>
                                </div>
                                <div class="flex gap-2">
                                    <x-ui.button type="submit" size="sm" variant="secondary">{{ __('actions.update') }}</x-ui.button>
                                    <button
                                        type="submit"
                                        form="delete-variant-{{ $variant->id }}"
                                        class="inline-flex items-center rounded-[7px] border border-red-300 px-2.5 py-1.5 text-xs font-semibold text-red-600 hover:bg-red-50 dark:border-red-700 dark:text-red-400 dark:hover:bg-red-500/10"
                                    >{{ __('actions.delete') }}</button>
                                </div>
                            </div>
                        </form>
                        <form id="delete-variant-{{ $variant->id }}" method="POST" action="{{ route('products.variants.destroy', [$product, $variant]) }}" class="hidden">
                            @csrf
                            @method('DELETE')
                        </form>
                    @endforeach
                </div>
            @endif
        </x-ui.card>
    </div>
</x-app.page>
@endsection
