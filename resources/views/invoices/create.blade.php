@extends('layouts.app')

@section('content')
    <x-app.page icon="receipt-percent"
        :title="__('invoices.create_title')"
        :subtitle="__('invoices.subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('invoices.title'), 'url' => route('invoices.index')],
            ['label' => __('invoices.create_title')],
        ]"
    >
        @include('invoices._form', [
            'action' => route('invoices.store'),
            'method' => 'POST',
            'invoice' => null,
            'customers' => $customers,
            'products' => $products,
            'taxesEnabled' => $taxesEnabled,
            'taxes' => $taxes,
        ])
    </x-app.page>
@endsection