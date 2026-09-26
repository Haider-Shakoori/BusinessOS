@extends('layouts.app')

@section('content')
@php
    $showAmount = static fn ($value) => !\App\Support\Decimal::eq((string)$value, '0') ? $value : '—';
@endphp
<x-app.page
    icon="ledger"
    :title="__('suppliers.ledger_page.title')"
    :subtitle="$supplier->name.' · '.__('suppliers.ledger_page.subtitle')"
    :breadcrumbs="[
        ['label' => __('modules.dashboard'), 'url' => route('app.home')],
        ['label' => __('suppliers.title'), 'url' => route('suppliers.index')],
        ['label' => $supplier->name, 'url' => route('suppliers.show', $supplier)],
        ['label' => __('suppliers.ledger_page.title')],
    ]"
>
    <x-slot:actions>
        <x-ui.button href="{{ route('suppliers.statement', ['supplier' => $supplier, 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}" variant="secondary" icon="document-text">{{ __('suppliers.statement') }}</x-ui.button>
    </x-slot:actions>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat-card :title="__('suppliers.total_purchased')" :value="$baseCurrency.' '.number_format((float)$summary['total_purchased'], 4)" icon="shopping-cart" />
        <x-ui.stat-card :title="__('suppliers.total_returned')" :value="$baseCurrency.' '.number_format((float)$summary['total_returned'], 4)" icon="arrow-uturn-left" tone="warning" />
        <x-ui.stat-card :title="__('suppliers.total_paid')" :value="$baseCurrency.' '.number_format((float)$summary['total_paid'], 4)" icon="banknotes" tone="success" />
        <x-ui.stat-card :title="__('suppliers.outstanding')" :value="$baseCurrency.' '.number_format((float)$summary['outstanding_balance'], 4)" icon="ledger" tone="danger" />
    </div>

    <x-ui.card class="mt-5">
        <form method="GET" action="{{ route('suppliers.ledger', $supplier) }}" class="grid gap-3 md:grid-cols-5">
            <x-ui.input name="search" :label="__('suppliers.ledger_page.search')" :value="$searchTerm" />
            <x-ui.select name="type" :label="__('suppliers.ledger_page.type')">
                <option value="">{{ __('suppliers.ledger_page.all_types') }}</option>
                @foreach(['purchase','return','payment'] as $value)
                    <option value="{{ $value }}" @selected($type === $value)>{{ __('suppliers.ledger_page.types.'.$value) }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input name="date_from" type="date" :label="__('suppliers.ledger_page.date_from')" :value="$dateFrom" />
            <x-ui.input name="date_to" type="date" :label="__('suppliers.ledger_page.date_to')" :value="$dateTo" />
            <div class="flex items-end gap-2">
                <x-ui.button type="submit" icon="funnel">{{ __('suppliers.ledger_page.filter') }}</x-ui.button>
                <x-ui.button href="{{ route('suppliers.ledger', $supplier) }}" variant="secondary">{{ __('suppliers.ledger_page.clear') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card class="mt-5">
        <div class="overflow-x-auto">
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('suppliers.ledger_page.columns.date') }}</x-ui.th>
                        <x-ui.th>{{ __('suppliers.ledger_page.columns.type') }}</x-ui.th>
                        <x-ui.th>{{ __('suppliers.ledger_page.columns.reference') }}</x-ui.th>
                        <x-ui.th>{{ __('suppliers.ledger_page.columns.description') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('suppliers.ledger_page.columns.debit') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('suppliers.ledger_page.columns.credit') }}</x-ui.th>
                        @if($showRunningBalance)<x-ui.th numeric>{{ __('suppliers.ledger_page.columns.balance') }}</x-ui.th>@endif
                    </tr>
                </x-slot:head>
                @forelse($rows as $row)
                    <tr class="{{ $row['reversed'] ? 'opacity-60' : '' }}">
                        <x-ui.td>{{ $row['date'] ?: '—' }}</x-ui.td>
                        <x-ui.td>
                            {{ __('suppliers.ledger_page.types.'.$row['type']) }}
                            @if($row['reversed']) <span class="text-xs text-slate-500">({{ __('suppliers.ledger_page.reversed') }})</span> @endif
                        </x-ui.td>
                        <x-ui.td>{{ $row['reference'] ?: '—' }}</x-ui.td>
                        <x-ui.td>{{ $row['description'] ?: '—' }}</x-ui.td>
                        <x-ui.td numeric>{{ $showAmount($row['debit']) }}</x-ui.td>
                        <x-ui.td numeric>{{ $showAmount($row['credit']) }}</x-ui.td>
                        @if($showRunningBalance)<x-ui.td numeric><span class="font-semibold">{{ $row['balance'] }}</span></x-ui.td>@endif
                    </tr>
                @empty
                    <tr><x-ui.td colspan="{{ $showRunningBalance ? 7 : 6 }}">{{ __('suppliers.ledger_page.no_transactions') }}</x-ui.td></tr>
                @endforelse
            </x-ui.table>
        </div>
        @if($showRunningBalance)
            <div class="mt-4 flex justify-end"><div class="rounded-lg bg-slate-50 px-4 py-3 text-sm dark:bg-slate-800"><span class="text-slate-500">{{ __('suppliers.ledger_page.closing_balance') }}:</span> <strong class="ms-2">{{ $baseCurrency }} {{ $closingBalance }}</strong></div></div>
        @endif
    </x-ui.card>
</x-app.page>
@endsection
