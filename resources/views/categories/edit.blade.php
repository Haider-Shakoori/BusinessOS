@extends('layouts.app')

@section('content')
    <x-app.page icon="tag"
        :title="__('categories.edit_title')"
        :subtitle="__('categories.subtitle')"
        :breadcrumbs="[
            ['label' => __('categories.title'), 'url' => route('categories.index')],
            ['label' => $category->name],
            ['label' => __('categories.edit_title')],
        ]"
    >
        @include('categories._form', [
            'action' => route('categories.update', $category),
            'method' => 'PATCH',
            'category' => $category,
        ])
    </x-app.page>
@endsection