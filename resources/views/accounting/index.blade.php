@extends('layouts.app')

@section('content')
<x-app.page icon="ledger" :title="__('operations.accounting.title')" :subtitle="__('operations.accounting.subtitle')">
    <x-slot:actions>
        <x-ui.button href="{{ route('accounting.reports') }}" variant="secondary" icon="chart-bar">
            {{ __('operations.accounting.financial_reports') }}
        </x-ui.button>
    </x-slot:actions>
    @if (session('status')) <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div> @endif

    <div class="grid gap-5 xl:grid-cols-2">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.accounting.add_account') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('accounting.accounts.store') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-ui.input name="code" :label="__('operations.accounting.code')" required />
                <x-ui.input name="name" :label="__('operations.accounting.name')" required />
                <x-ui.select name="type" :label="__('operations.accounting.type')" required>
                    @foreach(['asset','liability','equity','income','expense'] as $type)<option value="{{ $type }}">{{ __('operations.accounting.'.$type) }}</option>@endforeach
                </x-ui.select>
                <x-ui.select name="parent_id" :label="__('operations.accounting.parent')">
                    <option value="">{{ __('operations.accounting.none') }}</option>
                    @foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>@endforeach
                </x-ui.select>
                <div class="sm:col-span-2 flex justify-end"><x-ui.button type="submit" icon="plus">{{ __('operations.accounting.add_account') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.accounting.post_entry') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('accounting.journals.store') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-ui.input name="number" :label="__('operations.accounting.number')" required />
                <x-ui.input name="entry_date" type="date" :label="__('operations.accounting.date')" :value="now()->toDateString()" required />
                <x-ui.select name="debit_account_id" :label="__('operations.accounting.debit_account')" required>
                    @foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.select name="credit_account_id" :label="__('operations.accounting.credit_account')" required>
                    @foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.input name="amount" type="number" step="0.0001" min="0.0001" :label="__('operations.accounting.amount')" required />
                <x-ui.input name="description" :label="__('operations.accounting.description')" />
                <div class="sm:col-span-2 flex justify-end"><x-ui.button type="submit" icon="check-circle">{{ __('operations.accounting.post_entry') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>

    <div class="mt-5">
        <x-ui.card>
            <x-slot:header>
                <div>
                    <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.accounting.fiscal_periods') }}</h2>
                    <p class="mt-1 text-sm text-slate-500">{{ __('operations.accounting.fiscal_periods_help') }}</p>
                </div>
            </x-slot:header>

            <form method="POST" action="{{ route('accounting.fiscal-periods.store') }}" class="grid gap-4 md:grid-cols-4">
                @csrf
                <x-ui.input name="name" :label="__('operations.accounting.period_name')" required />
                <x-ui.input name="start_date" type="date" :label="__('operations.accounting.start_date')" required />
                <x-ui.input name="end_date" type="date" :label="__('operations.accounting.end_date')" required />
                <div class="flex items-end"><x-ui.button type="submit" icon="plus">{{ __('operations.accounting.create_period') }}</x-ui.button></div>
            </form>

            <div class="mt-5 overflow-x-auto">
                <x-ui.table>
                    <x-slot:head><tr>
                        <x-ui.th>{{ __('operations.accounting.period_name') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.accounting.period_range') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.accounting.status') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.accounting.actions') }}</x-ui.th>
                    </tr></x-slot:head>
                    @forelse($fiscalPeriods as $period)
                        <tr>
                            <x-ui.td>{{ $period->name }}</x-ui.td>
                            <x-ui.td>{{ $period->start_date?->format('Y-m-d') }} — {{ $period->end_date?->format('Y-m-d') }}</x-ui.td>
                            <x-ui.td>{{ __('operations.accounting.period_'.$period->status) }}</x-ui.td>
                            <x-ui.td>
                                @if($period->status === 'open')
                                    <form method="POST" action="{{ route('accounting.fiscal-periods.close', $period) }}" class="flex gap-2">
                                        @csrf
                                        <input name="note" class="min-w-40 rounded-md border border-slate-200 bg-white px-2 py-1 text-sm dark:border-slate-700 dark:bg-slate-900" placeholder="{{ __('operations.accounting.close_note') }}">
                                        <x-ui.button type="submit" variant="secondary">{{ __('operations.accounting.close_period') }}</x-ui.button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('accounting.fiscal-periods.reopen', $period) }}">
                                        @csrf
                                        <x-ui.button type="submit" variant="secondary">{{ __('operations.accounting.reopen_period') }}</x-ui.button>
                                    </form>
                                @endif
                            </x-ui.td>
                        </tr>
                    @empty
                        <tr><x-ui.td colspan="4">{{ __('operations.accounting.no_fiscal_periods') }}</x-ui.td></tr>
                    @endforelse
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.accounting.chart') }}</h2></x-slot:header>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-slot:head><tr><x-ui.th>{{ __('operations.accounting.code') }}</x-ui.th><x-ui.th>{{ __('operations.accounting.name') }}</x-ui.th><x-ui.th>{{ __('operations.accounting.type') }}</x-ui.th></tr></x-slot:head>
                    @foreach($accounts as $account)<tr><x-ui.td>{{ $account->code }}</x-ui.td><x-ui.td>{{ $account->name }}</x-ui.td><x-ui.td>{{ __('operations.accounting.'.$account->type) }}</x-ui.td></tr>@endforeach
                </x-ui.table>
            </div>
        </x-ui.card>

        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.accounting.journal') }}</h2></x-slot:header>
            <div class="space-y-3">
                @forelse($entries as $entry)
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <div class="flex justify-between gap-3"><div class="font-medium text-slate-900 dark:text-white">{{ $entry->number }}</div><div class="text-sm text-slate-500">{{ $entry->entry_date?->format('Y-m-d') }}</div></div>
                        <div class="mt-2 space-y-1 text-sm">
                            @foreach($entry->lines as $line)
                                <div class="flex justify-between"><span>{{ $line->account?->code }} — {{ $line->account?->name }}</span><span>@if((float)$line->debit > 0) D {{ $line->debit }} @else C {{ $line->credit }} @endif</span></div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('operations.accounting.no_entries') }}</p>
                @endforelse
            </div>
        </x-ui.card>
    </div>
</x-app.page>
@endsection
