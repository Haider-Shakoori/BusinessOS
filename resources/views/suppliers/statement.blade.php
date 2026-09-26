@extends('layouts.app')

@section('content')
@php
    $periodDebits = '0.0000';
    $periodCredits = '0.0000';
    foreach($rows as $row) {
        if(($row['type'] ?? '') === 'brought_forward') continue;
        $periodDebits = AppSupportDecimal::add($periodDebits, (string)$row['debit']);
        $periodCredits = AppSupportDecimal::add($periodCredits, (string)$row['credit']);
    }
    $showAmount = static fn ($value) => !AppSupportDecimal::eq((string)$value, '0') ? $value : '—';
@endphp

<x-app.page
    icon="document-text"
    :title="__('suppliers.statement_page.title')"
    :subtitle="__('suppliers.statement_page.subtitle', ['name' => $supplier->name])"
    :breadcrumbs="[
        ['label' => __('modules.dashboard'), 'url' => route('app.home')],
        ['label' => __('suppliers.title'), 'url' => route('suppliers.index')],
        ['label' => $supplier->name, 'url' => route('suppliers.show', $supplier)],
        ['label' => __('suppliers.statement_page.title')],
    ]"
>
    <x-slot:actions>
        <x-ui.button href="{{ route('suppliers.ledger', $supplier) }}" variant="secondary" icon="ledger">{{ __('suppliers.ledger') }}</x-ui.button>
        <x-ui.button type="button" variant="secondary" icon="document-text" x-on:click="window.print()" class="no-print">{{ __('suppliers.statement_page.print') }}</x-ui.button>
    </x-slot:actions>

    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-card sm:p-10 dark:border-slate-700 dark:bg-slate-800 print:rounded-none print:border-0 print:shadow-none print:p-0">
        <div class="flex flex-wrap items-start justify-between gap-5 border-b border-slate-200 pb-6 print:border-slate-300">
            <div>
                <h2 class="text-xl font-bold text-slate-900 dark:text-white print:text-slate-900">{{ $supplier->name }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $supplier->code }}</p>
                @if($supplier->address)<p class="mt-1 whitespace-pre-line text-sm text-slate-500">{{ $supplier->address }}</p>@endif
                @if($supplier->phone)<p class="text-sm text-slate-500" dir="ltr">{{ $supplier->phone }}</p>@endif
                @if($supplier->email)<p class="text-sm text-slate-500" dir="ltr">{{ $supplier->email }}</p>@endif
            </div>
            <div class="text-sm text-slate-500">
                <p>{{ __('suppliers.statement_page.period') }}: <strong class="text-slate-800 dark:text-slate-100 print:text-slate-800">{{ $dateFrom ?: __('suppliers.statement_page.all_dates') }} — {{ $dateTo ?: __('suppliers.statement_page.all_dates') }}</strong></p>
                <p class="mt-1">{{ __('suppliers.statement_page.as_of') }} {{ now()->format('Y-m-d') }}</p>
                <p class="mt-1 font-medium">{{ $baseCurrency }}</p>
            </div>
        </div>

        <div class="mt-6 overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead><tr class="border-b-2 border-slate-200 text-xs font-semibold uppercase text-slate-500">
                    <th class="py-2 pe-3 text-start">{{ __('suppliers.ledger_page.columns.date') }}</th>
                    <th class="py-2 pe-3 text-start">{{ __('suppliers.ledger_page.columns.type') }}</th>
                    <th class="py-2 pe-3 text-start">{{ __('suppliers.ledger_page.columns.reference') }}</th>
                    <th class="py-2 pe-3 text-start">{{ __('suppliers.ledger_page.columns.description') }}</th>
                    <th class="py-2 pe-3 text-end">{{ __('suppliers.ledger_page.columns.debit') }}</th>
                    <th class="py-2 pe-3 text-end">{{ __('suppliers.ledger_page.columns.credit') }}</th>
                    <th class="py-2 text-end">{{ __('suppliers.ledger_page.columns.balance') }}</th>
                </tr></thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr class="border-b border-slate-200 {{ $row['reversed'] ? 'opacity-60' : '' }}">
                            <td class="py-2 pe-3">{{ $row['date'] ?: '—' }}</td>
                            <td class="py-2 pe-3">{{ __('suppliers.ledger_page.types.'.$row['type']) }}</td>
                            <td class="py-2 pe-3">{{ $row['reference'] ?: '—' }}</td>
                            <td class="py-2 pe-3">{{ $row['description'] ?: '—' }}</td>
                            <td class="py-2 pe-3 text-end tabular-nums">{{ $showAmount($row['debit']) }}</td>
                            <td class="py-2 pe-3 text-end tabular-nums">{{ $showAmount($row['credit']) }}</td>
                            <td class="py-2 text-end font-medium tabular-nums">{{ $row['balance'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-6 text-center text-slate-500">{{ __('suppliers.ledger_page.no_transactions') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-6 grid gap-5 sm:grid-cols-2">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ $broughtForward !== null ? __('suppliers.statement_page.brought_forward') : __('suppliers.statement_page.opening') }}</dt><dd class="font-medium">{{ $broughtForward ?? $summary['opening_balance'] }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('suppliers.statement_page.debits_total') }}</dt><dd class="font-medium">{{ $periodDebits }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-slate-500">{{ __('suppliers.statement_page.credits_total') }}</dt><dd class="font-medium">{{ $periodCredits }}</dd></div>
            </dl>
            <div class="flex items-end justify-end">
                <div class="rounded-lg bg-slate-50 px-5 py-4 text-end dark:bg-slate-700/50 print:bg-slate-100">
                    <p class="text-sm text-slate-500">{{ __('suppliers.statement_page.closing_balance') }}</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900 dark:text-white print:text-slate-900">{{ $baseCurrency }} {{ $closingBalance }}</p>
                </div>
            </div>
        </div>

        <p class="mt-8 border-t border-slate-200 pt-4 text-xs text-slate-400">{{ __('suppliers.statement_page.prepared_on') }} {{ now()->format('Y-m-d H:i') }}</p>
    </div>
</x-app.page>
@endsection
