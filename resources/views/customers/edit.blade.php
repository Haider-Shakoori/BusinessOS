@extends('layouts.app')

@section('content')
    <x-app.page icon="users"
        :title="__('customers.edit_title')"
        :subtitle="__('customers.subtitle')"
        :breadcrumbs="[
            ['label' => __('customers.title'), 'url' => route('customers.index')],
            ['label' => $customer->name, 'url' => route('customers.show', $customer)],
            ['label' => __('customers.edit_title')],
        ]"
    >
        @include('customers._form', [
            'action' => route('customers.update', $customer),
            'method' => 'PATCH',
            'customer' => $customer,
        ])
    </x-app.page>
@endsection