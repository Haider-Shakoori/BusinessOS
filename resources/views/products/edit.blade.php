@extends('layouts.app')

@section('content')
    <x-app.page icon="cube"
        :title="__('products.edit_title')"
        :subtitle="__('products.subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('products.title'), 'url' => route('products.index')],
            ['label' => $product->name],
            ['label' => __('products.edit_title')],
        ]"
    >
        @include('products._form', [
            'action' => route('products.update', $product),
            'method' => 'PATCH',
            'product' => $product,
            'categories' => $categories,
            'units' => $units,
            'taxesEnabled' => $taxesEnabled,
            'taxes' => $taxes,
        ])
    </x-app.page>
@endsection