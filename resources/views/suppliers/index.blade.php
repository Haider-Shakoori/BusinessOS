@extends('layouts.app')

@section('content')
<x-app.page icon="users" :title="__('suppliers.title')" :subtitle="__('suppliers.subtitle')">
    @can('purchasing.manage')
        <x-slot:actions>
            <x-ui.button href="{{ route('suppliers.import') }}" variant="secondary" icon="arrow-up-tray">{{ __('suppliers.import') }}</x-ui.button>
        </x-slot:actions>
    @endcan

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif

    @can('purchasing.manage')
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('suppliers.add') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('suppliers.store') }}" class="grid gap-4 md:grid-cols-4">
                @csrf
                <x-ui.input name="code" :label="__('suppliers.fields.code')" />
                <x-ui.input name="name" :label="__('suppliers.fields.name')" required />
                <x-ui.input name="email" type="email" :label="__('suppliers.fields.email')" />
                <x-ui.input name="phone" :label="__('suppliers.fields.phone')" />
                <div class="md:col-span-2"><x-ui.input name="address" :label="__('suppliers.fields.address')" /></div>
                <x-ui.input name="opening_balance" type="number" step="0.0001" min="0" :label="__('suppliers.fields.opening_balance')" value="0" />
                <x-ui.input name="opening_balance_date" type="date" :label="__('suppliers.fields.opening_balance_date')" />
                <div class="md:col-span-3"><x-ui.input name="notes" :label="__('suppliers.fields.notes')" /></div>
                <div class="flex items-end justify-end"><x-ui.button type="submit" icon="plus">{{ __('suppliers.add') }}</x-ui.button></div>
                <input type="hidden" name="is_active" value="1">
            </form>
        </x-ui.card>
    @endcan

    <x-ui.card class="mt-5">
        <form method="GET" action="{{ route('suppliers.index') }}" class="grid gap-4 sm:grid-cols-3">
            <x-ui.input name="search" :label="__('suppliers.search')" :placeholder="__('suppliers.search_placeholder')" :value="$searchTerm" />
            <x-ui.select name="status" :label="__('suppliers.fields.status')">
                <option value="">{{ __('suppliers.all_statuses') }}</option>
                <option value="active" @selected($statusFilter === 'active')>{{ __('suppliers.active') }}</option>
                <option value="inactive" @selected($statusFilter === 'inactive')>{{ __('suppliers.inactive') }}</option>
            </x-ui.select>
            <div class="flex items-end gap-2">
                <x-ui.button type="submit" icon="search">{{ __('actions.search') }}</x-ui.button>
                @if($searchTerm !== '' || $statusFilter)
                    <x-ui.button href="{{ route('suppliers.index') }}" variant="secondary">{{ __('actions.clear') }}</x-ui.button>
                @endif
            </div>
        </form>
    </x-ui.card>

    <div class="mt-5 overflow-x-auto">
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th>{{ __('suppliers.fields.code') }}</x-ui.th>
                    <x-ui.th>{{ __('suppliers.fields.name') }}</x-ui.th>
                    <x-ui.th>{{ __('suppliers.fields.phone') }}</x-ui.th>
                    <x-ui.th>{{ __('suppliers.fields.email') }}</x-ui.th>
                    <x-ui.th>{{ __('suppliers.fields.status') }}</x-ui.th>
                    <x-ui.th></x-ui.th>
                </tr>
            </x-slot:head>
            @forelse($suppliers as $supplier)
                <tr>
                    <x-ui.td>{{ $supplier->code ?: '—' }}</x-ui.td>
                    <x-ui.td><a class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400" href="{{ route('suppliers.show', $supplier) }}">{{ $supplier->name }}</a></x-ui.td>
                    <x-ui.td>{{ $supplier->phone ?: '—' }}</x-ui.td>
                    <x-ui.td>{{ $supplier->email ?: '—' }}</x-ui.td>
                    <x-ui.td><x-ui.badge :tone="$supplier->is_active ? 'success' : 'neutral'">{{ $supplier->is_active ? __('suppliers.active') : __('suppliers.inactive') }}</x-ui.badge></x-ui.td>
                    <x-ui.td class="text-end">
                        <div class="flex justify-end gap-2">
                            <x-ui.button href="{{ route('suppliers.ledger', $supplier) }}" size="sm" variant="secondary">{{ __('suppliers.ledger_link') }}</x-ui.button>
                            <x-ui.button href="{{ route('suppliers.show', $supplier) }}" size="sm">{{ __('suppliers.view') }}</x-ui.button>
                        </div>
                    </x-ui.td>
                </tr>
            @empty
                <tr><x-ui.td colspan="6">{{ __('suppliers.no_suppliers') }}</x-ui.td></tr>
            @endforelse
        </x-ui.table>
    </div>

    <div class="mt-5">{{ $suppliers->links() }}</div>
</x-app.page>
@endsection
