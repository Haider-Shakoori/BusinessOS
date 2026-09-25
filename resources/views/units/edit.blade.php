@extends('layouts.app')

@section('content')
    <x-app.page icon="cube"
        :title="__('units.edit_title')"
        :subtitle="__('units.subtitle')"
        :breadcrumbs="[
            ['label' => __('units.title'), 'url' => route('units.index')],
            ['label' => $unit->name],
            ['label' => __('units.edit_title')],
        ]"
    >
        @include('units._form', [
            'action' => route('units.update', $unit),
            'method' => 'PATCH',
            'unit' => $unit,
        ])
    </x-app.page>
@endsection