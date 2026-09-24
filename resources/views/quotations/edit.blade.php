@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('quotations.edit_title')"
        :subtitle="__('quotations.subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('quotations.title'), 'url' => route('quotations.index')],
            ['label' => $quotation->quotation_number, 'url' => route('quotations.show', $quotation)],
            ['label' => __('quotations.edit_title')],
        ]"
    >
        @include('quotations._form', [
            'action' => route('quotations.update', $quotation),
            'method' => 'PATCH',
            'quotation' => $quotation,
            'customers' => $customers,
            'products' => $products,
            'taxesEnabled' => $taxesEnabled,
            'taxes' => $taxes,
        ])
    </x-app.page>
@endsection