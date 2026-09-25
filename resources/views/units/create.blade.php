@extends('layouts.app')

@section('content')
    <x-app.page icon="cube"
        :title="__('units.create_title')"
        :subtitle="__('units.subtitle')"
        :breadcrumbs="[
            ['label' => __('units.title'), 'url' => route('units.index')],
            ['label' => __('units.create_title')],
        ]"
    >
        @include('units._form', [
            'action' => route('units.store'),
            'method' => 'POST',
            'unit' => null,
        ])
    </x-app.page>
@endsection