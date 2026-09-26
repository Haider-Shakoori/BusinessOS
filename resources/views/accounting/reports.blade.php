@extends('layouts.app')

@section('content')
<x-app.page icon="chart-bar" :title="__('operations.accounting.financial_reports')" :subtitle="__('operations.accounting.financial_reports_subtitle')">
    <x-slot:actions>
        <x-ui.button href="{{ route('accounting.index') }}" variant="secondary">
            {{ __('operations.accounting.back_to_accounting') }}
        </x-ui.button>
    </x-slot:actions>

    <x-ui.card>
        <form method="GET" action="{{ route('accounting.reports') }}" class="grid gap-4 md:grid-cols-4">
            <x-ui.input name="date_from" type="date" :label="__('operations.accounting.date_from')" :value="$dateFrom" />
            <x-ui.input name="date_to" type="date" :label="__('operations.accounting.date_to')" :value="$dateTo" />
            <x-ui.select name="account_id" :label="__('operations.accounting.ledger_account')">
                @foreach($accounts as $account)
                    <option value="{{ $account->id }}" @selected((int) $accountId === (int) $account->id)>
                        {{ $account->code }} — {{ $account->name }}
                    </option>
                @endforeach
            </x-ui.select>
            <div class="flex items-end">
                <x-ui.button type="submit" icon="search">{{ __('operations.accounting.apply_filters') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.accounting.trial_balance') }}</h2></x-slot:header>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('operations.accounting.code') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.accounting.name') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.accounting.debit') }}</x-ui.th>
                            <x-ui.th>{{ __('operations.accounting.credit') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    @foreach($trialBalance as $row)
                        @if((float) $row['closing_debit'] !== 0.0 || (float) $row['closing_credit'] !== 0.0)
                            <tr>
                                <x-ui.td>{{ $row['code'] }}</x-ui.td>
                                <x-ui.td>{{ $row['name'] }}</x-ui.td>
                                <x-ui.td>{{ number_format((float) $row['closing_debit'], 2) }}</x-ui.td>
                                <x-ui.td>{{ number_format((float) $row['closing_credit'], 2) }}</x-ui.td>
                            </tr>
                        @endif
                    @endforeach
                    <tr class="font-semibold">
                        <x-ui.td colspan="2">{{ __('operations.accounting.total') }}</x-ui.td>
                        <x-ui.td>{{ number_format((float) $trialBalance->sum(fn($row) => (float) $row['closing_debit']), 2) }}</x-ui.td>
                        <x-ui.td>{{ number_format((float) $trialBalance->sum(fn($row) => (float) $row['closing_credit']), 2) }}</x-ui.td>
                    </tr>
                </x-ui.table>
            </div>
        </x-ui.card>

        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.accounting.profit_loss') }}</h2></x-slot:header>
            <div class="space-y-4">
                <div>
                    <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('operations.accounting.income') }}</h3>
                    <div class="mt-2 space-y-2">
                        @foreach($profitLoss['income'] as $row)
                            <div class="flex justify-between gap-4 text-sm"><span>{{ $row['code'] }} — {{ $row['name'] }}</span><span>{{ number_format((float) $row['balance'], 2) }}</span></div>
                        @endforeach
                    </div>
                    <div class="mt-3 flex justify-between border-t border-slate-200 pt-2 font-semibold dark:border-slate-700">
                        <span>{{ __('operations.accounting.total_income') }}</span><span>{{ number_format((float) $profitLoss['total_income'], 2) }}</span>
                    </div>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('operations.accounting.expense') }}</h3>
                    <div class="mt-2 space-y-2">
                        @foreach($profitLoss['expenses'] as $row)
                            <div class="flex justify-between gap-4 text-sm"><span>{{ $row['code'] }} — {{ $row['name'] }}</span><span>{{ number_format((float) $row['balance'], 2) }}</span></div>
                        @endforeach
                    </div>
                    <div class="mt-3 flex justify-between border-t border-slate-200 pt-2 font-semibold dark:border-slate-700">
                        <span>{{ __('operations.accounting.total_expenses') }}</span><span>{{ number_format((float) $profitLoss['total_expenses'], 2) }}</span>
                    </div>
                </div>
                <div class="flex justify-between rounded-lg bg-slate-50 p-3 font-semibold dark:bg-slate-800">
                    <span>{{ __('operations.accounting.net_profit') }}</span>
                    <span>{{ number_format((float) $profitLoss['net_profit'], 2) }}</span>
                </div>
            </div>
        </x-ui.card>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.accounting.balance_sheet') }}</h2></x-slot:header>
            <div class="space-y-4 text-sm">
                <div>
                    <h3 class="font-semibold text-slate-700 dark:text-slate-200">{{ __('operations.accounting.assets') }}</h3>
                    @foreach($balanceSheet['assets'] as $row)
                        <div class="mt-2 flex justify-between gap-4"><span>{{ $row['code'] }} — {{ $row['name'] }}</span><span>{{ number_format((float) $row['balance'], 2) }}</span></div>
                    @endforeach
                    <div class="mt-2 flex justify-between border-t border-slate-200 pt-2 font-semibold dark:border-slate-700"><span>{{ __('operations.accounting.total_assets') }}</span><span>{{ number_format((float) $balanceSheet['total_assets'], 2) }}</span></div>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-700 dark:text-slate-200">{{ __('operations.accounting.liabilities') }}</h3>
                    @foreach($balanceSheet['liabilities'] as $row)
                        <div class="mt-2 flex justify-between gap-4"><span>{{ $row['code'] }} — {{ $row['name'] }}</span><span>{{ number_format((float) $row['balance'], 2) }}</span></div>
                    @endforeach
                    <div class="mt-2 flex justify-between border-t border-slate-200 pt-2 font-semibold dark:border-slate-700"><span>{{ __('operations.accounting.total_liabilities') }}</span><span>{{ number_format((float) $balanceSheet['total_liabilities'], 2) }}</span></div>
                </div>
                <div>
                    <h3 class="font-semibold text-slate-700 dark:text-slate-200">{{ __('operations.accounting.equity') }}</h3>
                    @foreach($balanceSheet['equity'] as $row)
                        <div class="mt-2 flex justify-between gap-4"><span>{{ $row['code'] }} — {{ $row['name'] }}</span><span>{{ number_format((float) $row['balance'], 2) }}</span></div>
                    @endforeach
                    <div class="mt-2 flex justify-between"><span>{{ __('operations.accounting.current_earnings') }}</span><span>{{ number_format((float) $balanceSheet['current_earnings'], 2) }}</span></div>
                    <div class="mt-2 flex justify-between border-t border-slate-200 pt-2 font-semibold dark:border-slate-700"><span>{{ __('operations.accounting.total_liabilities_equity') }}</span><span>{{ number_format((float) $balanceSheet['total_liabilities_equity'], 2) }}</span></div>
                </div>
                <div class="flex justify-between rounded-lg bg-slate-50 p-3 font-semibold dark:bg-slate-800">
                    <span>{{ __('operations.accounting.balance_difference') }}</span>
                    <span>{{ number_format((float) $balanceSheet['difference'], 2) }}</span>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.accounting.general_ledger') }}</h2></x-slot:header>
            @if($ledger)
                <div class="mb-3 flex flex-wrap justify-between gap-2 text-sm">
                    <span class="font-semibold">{{ $ledger['account']->code }} — {{ $ledger['account']->name }}</span>
                    <span>{{ __('operations.accounting.opening_balance') }}: {{ number_format((float) $ledger['opening_balance'], 2) }}</span>
                </div>
                <div class="overflow-x-auto">
                    <x-ui.table>
                        <x-slot:head>
                            <tr>
                                <x-ui.th>{{ __('operations.accounting.date') }}</x-ui.th>
                                <x-ui.th>{{ __('operations.accounting.number') }}</x-ui.th>
                                <x-ui.th>{{ __('operations.accounting.description') }}</x-ui.th>
                                <x-ui.th>{{ __('operations.accounting.debit') }}</x-ui.th>
                                <x-ui.th>{{ __('operations.accounting.credit') }}</x-ui.th>
                                <x-ui.th>{{ __('operations.accounting.running_balance') }}</x-ui.th>
                            </tr>
                        </x-slot:head>
                        @forelse($ledger['lines'] as $line)
                            <tr>
                                <x-ui.td>{{ $line['entry_date'] }}</x-ui.td>
                                <x-ui.td>{{ $line['number'] }}</x-ui.td>
                                <x-ui.td>{{ $line['description'] }}</x-ui.td>
                                <x-ui.td>{{ number_format((float) $line['debit'], 2) }}</x-ui.td>
                                <x-ui.td>{{ number_format((float) $line['credit'], 2) }}</x-ui.td>
                                <x-ui.td>{{ number_format((float) $line['running_balance'], 2) }}</x-ui.td>
                            </tr>
                        @empty
                            <tr><x-ui.td colspan="6">{{ __('operations.accounting.no_entries') }}</x-ui.td></tr>
                        @endforelse
                    </x-ui.table>
                </div>
                <div class="mt-3 text-end font-semibold">{{ __('operations.accounting.closing_balance') }}: {{ number_format((float) $ledger['closing_balance'], 2) }}</div>
            @else
                <p class="text-sm text-slate-500">{{ __('operations.accounting.no_accounts') }}</p>
            @endif
        </x-ui.card>
    </div>
</x-app.page>
@endsection
