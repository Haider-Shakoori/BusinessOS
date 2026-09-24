@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('taxes.edit_title')"
        :subtitle="__('taxes.subtitle')"
        :breadcrumbs="[
            ['label' => __('taxes.title'), 'url' => route('taxes.index')],
            ['label' => $tax->name],
            ['label' => __('taxes.edit_title')],
        ]"
    >
        @include('taxes._form', [
            'action' => route('taxes.update', $tax),
            'method' => 'PATCH',
            'tax' => $tax,
        ])
    </x-app.page>
@endsection