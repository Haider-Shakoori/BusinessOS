@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('taxes.create_title')"
        :subtitle="__('taxes.subtitle')"
        :breadcrumbs="[
            ['label' => __('taxes.title'), 'url' => route('taxes.index')],
            ['label' => __('taxes.create_title')],
        ]"
    >
        @include('taxes._form', [
            'action' => route('taxes.store'),
            'method' => 'POST',
            'tax' => null,
        ])
    </x-app.page>
@endsection