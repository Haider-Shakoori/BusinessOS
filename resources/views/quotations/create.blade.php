@extends('layouts.app')

@section('content')
    <x-app.page icon="document-text"
        :title="__('quotations.create_title')"
        :subtitle="__('quotations.subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('quotations.title'), 'url' => route('quotations.index')],
            ['label' => __('quotations.create_title')],
        ]"
    >
        @include('quotations._form', [
            'action' => route('quotations.store'),
            'method' => 'POST',
            'quotation' => null,
            'customers' => $customers,
            'products' => $products,
            'taxesEnabled' => $taxesEnabled,
            'taxes' => $taxes,
        ])
    </x-app.page>
@endsection