@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('categories.create_title')"
        :subtitle="__('categories.subtitle')"
        :breadcrumbs="[
            ['label' => __('categories.title'), 'url' => route('categories.index')],
            ['label' => __('categories.create_title')],
        ]"
    >
        @include('categories._form', [
            'action' => route('categories.store'),
            'method' => 'POST',
            'category' => null,
        ])
    </x-app.page>
@endsection