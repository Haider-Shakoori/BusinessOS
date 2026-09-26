@extends('layouts.app')

@section('content')
<x-app.page
    icon="users"
    :title="__('suppliers.create')"
    :subtitle="__('suppliers.subtitle')"
    :breadcrumbs="[
        ['label' => __('modules.dashboard'), 'url' => route('app.home')],
        ['label' => __('suppliers.title'), 'url' => route('suppliers.index')],
        ['label' => __('suppliers.create')],
    ]"
>
    @include('suppliers._form', [
        'action' => route('suppliers.store'),
        'method' => 'POST',
    ])
</x-app.page>
@endsection
