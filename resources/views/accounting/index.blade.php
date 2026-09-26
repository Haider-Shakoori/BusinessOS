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
