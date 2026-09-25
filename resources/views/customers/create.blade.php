@extends('layouts.app')

@section('content')
    <x-app.page icon="users"
        :title="__('customers.create_title')"
        :subtitle="__('customers.subtitle')"
        :breadcrumbs="[
            ['label' => __('customers.title'), 'url' => route('customers.index')],
            ['label' => __('customers.create_title')],
        ]"
    >
        @include('customers._form', [
            'action' => route('customers.store'),
            'method' => 'POST',
            'customer' => null,
        ])
    </x-app.page>
@endsection