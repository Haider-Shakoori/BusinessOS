@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('invoices.edit_title')"
        :subtitle="__('invoices.subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('invoices.title'), 'url' => route('invoices.index')],
            ['label' => $invoice->invoice_number, 'url' => route('invoices.show', $invoice)],
            ['label' => __('invoices.edit_title')],
        ]"
    >
        @include('invoices._form', [
            'action' => route('invoices.update', $invoice),
            'method' => 'PATCH',
            'invoice' => $invoice,
            'customers' => $customers,
            'products' => $products,
            'taxesEnabled' => $taxesEnabled,
            'taxes' => $taxes,
        ])
    </x-app.page>
@endsection