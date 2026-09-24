@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('expenses.edit_title')"
        :subtitle="$expense->expense_number"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('expenses.title'), 'url' => route('expenses.index')],
            ['label' => __('expenses.edit_title')],
        ]"
    >
        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        @include('expenses._form', [
            'expense' => $expense,
            'categories' => $categories,
            'action' => route('expenses.update', $expense),
            'method' => 'PATCH',
        ])
    </x-app.page>
@endsection