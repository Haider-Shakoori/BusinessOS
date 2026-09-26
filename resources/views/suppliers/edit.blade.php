@extends('layouts.app')

@section('content')
<x-app.page
    icon="users"
    :title="__('suppliers.edit')"
    :subtitle="$supplier->name"
    :breadcrumbs="[
        ['label' => __('modules.dashboard'), 'url' => route('app.home')],
        ['label' => __('suppliers.title'), 'url' => route('suppliers.index')],
        ['label' => $supplier->name, 'url' => route('suppliers.show', $supplier)],
        ['label' => __('suppliers.edit')],
    ]"
>
    @include('suppliers._form', [
        'supplier' => $supplier,
        'action' => route('suppliers.update', $supplier),
        'method' => 'PATCH',
    ])
</x-app.page>
@endsection
