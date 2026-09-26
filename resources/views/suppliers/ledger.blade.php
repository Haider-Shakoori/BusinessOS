@extends('layouts.app')

@section('content')
<x-app.page icon="ledger" :title="__('suppliers.ledger.title')" :subtitle="$supplier->name">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="{{ route('suppliers.show', $supplier) }}" variant="secondary">{{ __('suppliers.details') }}</x-ui.button>
            <x-ui.button href="{{ route('suppliers.statement', ['supplier' => $supplier, 'date_from' => $dateFrom, 'date_to' => $dateTo]) }}" variant="secondary" icon="document-text">{{ __('suppliers.statement_link') }}</x-ui.button>
        </div>
    </x-slot:actions>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat-card :title="__('suppliers.summary.outstanding')" :value="$summary['outstanding_balance']" icon="banknotes" tone="warning" />
        <x-ui.stat-card :title="__('suppliers.summary.purchases')" :value="$summary['total_purchases']" icon="shopping-cart" tone="info" />
        <x-ui.stat-card :title="__('suppliers.summary.returns')" :value="$summary['total_returns']" icon="arrow-uturn-left" tone="neutral" />
        <x-ui.stat-card :title="__('suppliers.summary.paid')" :value="$summary['total_paid']" icon="check-circle" tone="success" />
    </div>

    <x-ui.card class="mt-5">
        <form method="GET" action="{{ route('suppliers.ledger', $supplier) }}" class="grid gap-4 lg:grid-cols-5">
            <x-ui.input name="date_from" type="date" :label="__('suppliers.ledger.date_from')" :value="$dateFrom" />
            <x-ui.input name="date_to" type="date" :label="__('suppliers.ledger.date_to')" :value="$dateTo" />
            <x-ui.select name="type" :label="__('suppliers.ledger.type')">
                <option value="">{{ __('suppliers.ledger.all_types') }}</option>
                @foreach(['opening','purchase','return','payment','reversal'] as $rowType)
                    <option value="{{ $rowType }}" @selected($type === $rowType)>{{ __('suppliers.ledger.'.($rowType === 'payment' ? 'payment_type' : $rowType)) }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input name="search" :label="__('suppliers.search')" :value="$searchTerm" />
            <div class="flex items-end gap-2">
                <x-ui.button type="submit" icon="search">{{ __('suppliers.ledger.apply') }}</x-ui.button>
                <x-ui.button href="{{ route('suppliers.ledger', $supplier) }}" variant="secondary">{{ __('actions.clear') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="mt-5 overflow-x-auto">
        <x-ui.table>
            <x-slot:head>
                <tr>
                    <x-ui.th>{{ __('suppliers.ledger.columns.date') }}</x-ui.th>
                    <x-ui.th>{{ __('suppliers.ledger.columns.type') }}</x-ui.th>
                    <x-ui.th>{{ __('suppliers.ledger.columns.reference') }}</x-ui.th>
                    <x-ui.th>{{ __('suppliers.ledger.columns.description') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('suppliers.ledger.columns.debit') }}</x-ui.th>
                    <x-ui.th class="text-end">{{ __('suppliers.ledger.columns.credit') }}</x-ui.th>
                    @if($showRunningBalance)<x-ui.th class="text-end">{{ __('suppliers.ledger.columns.balance') }}</x-ui.th>@endif
                </tr>
            </x-slot:head>
            @forelse($rows as $row)
                <tr @class(['opacity-60' => $row['reversed'] ?? false])>
                    <x-ui.td>{{ $row['date'] ?: '—' }}</x-ui.td>
                    <x-ui.td>{{ __('suppliers.ledger.'.(($row['type'] ?? '') === 'payment' ? 'payment_type' : ($row['type'] ?? 'opening'))) }}</x-ui.td>
                    <x-ui.td>{{ $row['reference'] ?: '—' }}</x-ui.td>
                    <x-ui.td>{{ $row['description'] ?: '—' }}</x-ui.td>
                    <x-ui.td class="text-end">{{ $row['debit'] }}</x-ui.td>
                    <x-ui.td class="text-end">{{ $row['credit'] }}</x-ui.td>
                    @if($showRunningBalance)<x-ui.td class="text-end font-semibold">{{ $row['balance'] }}</x-ui.td>@endif
                </tr>
            @empty
                <tr><x-ui.td colspan="{{ $showRunningBalance ? 7 : 6 }}">{{ __('suppliers.ledger.empty') }}</x-ui.td></tr>
            @endforelse
            <x-slot:footer>
                <div class="flex justify-end px-4 py-3 text-sm">
                    <span class="text-slate-500">{{ __('suppliers.statement.closing') }}:</span>
                    <strong class="ms-2">{{ $closingBalance }} {{ $baseCurrency }}</strong>
                </div>
            </x-slot:footer>
        </x-ui.table>
    </div>

    @unless($showRunningBalance)
        <p class="mt-3 text-xs text-slate-500">{{ __('suppliers.ledger.filtered_note') }}</p>
    @endunless
</x-app.page>
@endsection
