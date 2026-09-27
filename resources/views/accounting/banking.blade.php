@extends('layouts.app')

@section('title', __('operations.accounting.banking'))

@section('content')
<x-app.page :title="__('operations.accounting.banking')" :subtitle="__('operations.accounting.banking_help')">
    <div class="mb-5 flex justify-end"><x-ui.button href="{{ route('accounting.index') }}" variant="secondary">{{ __('operations.accounting.back_to_accounting') }}</x-ui.button></div>
    <div class="grid gap-5 xl:grid-cols-2">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold">{{ __('operations.accounting.financial_accounts') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('accounting.banking.accounts.store') }}" class="grid gap-3 md:grid-cols-2">@csrf
                <x-ui.input name="name" :label="__('operations.accounting.name')" required />
                <x-ui.select name="type" :label="__('operations.accounting.type')" required><option value="cash">{{ __('operations.accounting.cash') }}</option><option value="bank">{{ __('operations.accounting.bank') }}</option></x-ui.select>
                <x-ui.select name="account_id" :label="__('operations.accounting.ledger_account')" required>@foreach($glAccounts as $gl)<option value="{{ $gl->id }}">{{ $gl->code }} — {{ $gl->name }}</option>@endforeach</x-ui.select>
                <x-ui.input name="institution" :label="__('operations.accounting.institution')" />
                <x-ui.input name="account_number" :label="__('operations.accounting.account_number')" />
                <div class="flex items-end"><x-ui.button type="submit">{{ __('operations.accounting.add_financial_account') }}</x-ui.button></div>
            </form>
            <div class="mt-4 space-y-2">@foreach($accounts as $account)<div class="rounded-lg border p-3 dark:border-slate-700"><b>{{ $account->name }}</b><div class="text-sm text-slate-500">{{ $account->account->code }} — {{ $account->account->name }}</div></div>@endforeach</div>
        </x-ui.card>
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold">{{ __('operations.accounting.internal_transfer') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('accounting.banking.transfers.store') }}" class="grid gap-3 md:grid-cols-2">@csrf
                <x-ui.select name="from_financial_account_id" :label="__('operations.accounting.from_account')" required>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</x-ui.select>
                <x-ui.select name="to_financial_account_id" :label="__('operations.accounting.to_account')" required>@foreach($accounts as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</x-ui.select>
                <x-ui.input name="amount" type="number" step="0.0001" min="0.0001" :label="__('operations.accounting.amount')" required />
                <x-ui.input name="entry_date" type="date" :value="now()->toDateString()" :label="__('operations.accounting.date')" required />
                <x-ui.input name="memo" :label="__('operations.accounting.description')" />
                <div class="flex items-end"><x-ui.button type="submit">{{ __('operations.accounting.post_transfer') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>
    <div class="mt-5"><x-ui.card>
        <x-slot:header><h2 class="font-semibold">{{ __('operations.accounting.bank_reconciliation') }}</h2></x-slot:header>
        <form method="POST" action="{{ route('accounting.banking.reconciliations.store') }}" class="grid gap-3 md:grid-cols-4">@csrf
            <x-ui.select name="financial_account_id" :label="__('operations.accounting.financial_account')" required>@foreach($accounts->where('type','bank') as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</x-ui.select>
            <x-ui.input name="statement_date" type="date" :label="__('operations.accounting.statement_date')" required />
            <x-ui.input name="statement_balance" type="number" step="0.0001" :label="__('operations.accounting.statement_balance')" required />
            <div class="flex items-end"><x-ui.button type="submit">{{ __('operations.accounting.start_reconciliation') }}</x-ui.button></div>
        </form>
        <div class="mt-4 overflow-x-auto"><x-ui.table><x-slot:head><tr><x-ui.th>{{ __('operations.accounting.financial_account') }}</x-ui.th><x-ui.th>{{ __('operations.accounting.statement_date') }}</x-ui.th><x-ui.th>{{ __('operations.accounting.statement_balance') }}</x-ui.th><x-ui.th>{{ __('operations.accounting.status') }}</x-ui.th><x-ui.th>{{ __('operations.accounting.actions') }}</x-ui.th></tr></x-slot:head>
        @foreach($reconciliations as $r)<tr><x-ui.td>{{ $r->financialAccount->name }}</x-ui.td><x-ui.td>{{ $r->statement_date->format('Y-m-d') }}</x-ui.td><x-ui.td>{{ $r->statement_balance }}</x-ui.td><x-ui.td>{{ $r->status }}</x-ui.td><x-ui.td><a class="text-indigo-600" href="{{ route('accounting.banking.reconciliations.show',$r) }}">{{ __('operations.accounting.open') }}</a></x-ui.td></tr>@endforeach
        </x-ui.table></div>
    </x-ui.card></div>
</x-app.page>
@endsection
