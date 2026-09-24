@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('expenses.create_title')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('expenses.title'), 'url' => route('expenses.index')],
            ['label' => __('expenses.create_title')],
        ]"
    >
        @include('expenses._form', [
            'expense' => null,
            'categories' => $categories,
            'action' => route('expenses.store'),
        ])
    </x-app.page>
@endsection