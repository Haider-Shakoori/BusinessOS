@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('products.create_title')"
        :subtitle="__('products.subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('products.title'), 'url' => route('products.index')],
            ['label' => __('products.create_title')],
        ]"
    >
        @include('products._form', [
            'action' => route('products.store'),
            'method' => 'POST',
            'product' => null,
            'categories' => $categories,
            'units' => $units,
            'taxesEnabled' => $taxesEnabled,
            'taxes' => $taxes,
        ])
    </x-app.page>
@endsection