@extends('layouts.app')
@section('title', __('operations.accounting.bank_reconciliation'))
@section('content')
<x-app.page :title="$reconciliation->financialAccount->name" :subtitle="__('operations.accounting.reconciliation_help')">
    <div class="grid gap-4 md:grid-cols-3">
        <x-ui.card><div class="text-sm text-slate-500">{{ __('operations.accounting.book_balance') }}</div><div class="text-xl font-semibold">{{ $summary['book_balance'] }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">{{ __('operations.accounting.statement_balance') }}</div><div class="text-xl font-semibold">{{ $summary['statement_balance'] }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">{{ __('operations.accounting.difference') }}</div><div class="text-xl font-semibold">{{ $summary['difference'] }}</div></x-ui.card>
    </div>
    <div class="mt-5"><x-ui.card><x-slot:header><h2 class="font-semibold">{{ __('operations.accounting.unreconciled_transactions') }}</h2></x-slot:header>
        <div class="overflow-x-auto"><x-ui.table><x-slot:head><tr><x-ui.th>{{ __('operations.accounting.date') }}</x-ui.th><x-ui.th>{{ __('operations.accounting.number') }}</x-ui.th><x-ui.th>{{ __('operations.accounting.debit') }}</x-ui.th><x-ui.th>{{ __('operations.accounting.credit') }}</x-ui.th><x-ui.th>{{ __('operations.accounting.actions') }}</x-ui.th></tr></x-slot:head>
        @foreach($candidates as $line)<tr><x-ui.td>{{ $line->journalEntry->entry_date->format('Y-m-d') }}</x-ui.td><x-ui.td>{{ $line->journalEntry->number }}</x-ui.td><x-ui.td>{{ $line->debit }}</x-ui.td><x-ui.td>{{ $line->credit }}</x-ui.td><x-ui.td>@if($reconciliation->status === 'draft')<form method="POST" action="{{ route('accounting.banking.reconciliations.match',$reconciliation) }}">@csrf<input type="hidden" name="journal_line_id" value="{{ $line->id }}"><x-ui.button type="submit" variant="secondary">{{ __('operations.accounting.match') }}</x-ui.button></form>@endif</x-ui.td></tr>@endforeach
        </x-ui.table></div>
        @if($reconciliation->status === 'draft')<form method="POST" action="{{ route('accounting.banking.reconciliations.complete',$reconciliation) }}" class="mt-4 flex justify-end">@csrf<x-ui.button type="submit">{{ __('operations.accounting.complete_reconciliation') }}</x-ui.button></form>@endif
    </x-ui.card></div>
</x-app.page>
@endsection
