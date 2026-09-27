@extends('layouts.app')

@section('content')
    @php
        $formatter = app(\App\Support\LocalizedFormatter::class);
        $money = fn (string $amount): string => $formatter->currency($amount, $report['currency']);
    @endphp

    <x-app.page
        :title="__('operations.accounting.combined_reporting')"
        :subtitle="__('operations.accounting.combined_reporting_help')"
        icon="chart-bar"
    >
        <x-ui.alert type="warning">
            {{ __('operations.accounting.combined_reporting_warning') }}
        </x-ui.alert>

        <x-ui.card class="mt-5">
            <form method="GET" class="grid gap-4 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">
                        {{ __('operations.accounting.businesses') }}
                    </label>
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach($availableBusinesses as $business)
                            <label class="flex items-center gap-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                <input type="checkbox" name="business_ids[]" value="{{ $business->id }}" @checked(in_array($business->id, $selectedIds, true))>
                                <span>{{ $business->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                <x-ui.input name="date_from" type="date" :value="$dateFrom" :label="__('operations.accounting.start_date')" />
                <div>
                    <x-ui.input name="date_to" type="date" :value="$dateTo" :label="__('operations.accounting.end_date')" />
                    <div class="mt-3 flex justify-end"><x-ui.button type="submit">{{ __('operations.accounting.refresh_report') }}</x-ui.button></div>
                </div>
            </form>
        </x-ui.card>

        <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat-card :title="__('operations.accounting.total_income')" :value="$money($report['combined']['total_income'])" icon="arrow-trending-up" tone="success" />
            <x-ui.stat-card :title="__('operations.accounting.total_expenses')" :value="$money($report['combined']['total_expenses'])" icon="arrow-trending-down" tone="danger" />
            <x-ui.stat-card :title="__('operations.accounting.net_profit')" :value="$money($report['combined']['net_profit'])" icon="chart-bar" tone="brand" />
            <x-ui.stat-card :title="__('operations.accounting.total_assets')" :value="$money($report['combined']['total_assets'])" icon="banknotes" />
        </div>

        <div class="mt-5 overflow-x-auto">
            <x-ui.table :caption="__('operations.accounting.combined_profit_loss')">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('operations.accounting.business') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('operations.accounting.total_income') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('operations.accounting.total_expenses') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('operations.accounting.net_profit') }}</x-ui.th>
                    </tr>
                </x-slot:head>
                @foreach($report['rows'] as $row)
                    <tr>
                        <x-ui.td><span class="font-semibold">{{ $row['business']->name }}</span></x-ui.td>
                        <x-ui.td numeric>{{ $money($row['profit_loss']['total_income']) }}</x-ui.td>
                        <x-ui.td numeric>{{ $money($row['profit_loss']['total_expenses']) }}</x-ui.td>
                        <x-ui.td numeric>{{ $money($row['profit_loss']['net_profit']) }}</x-ui.td>
                    </tr>
                @endforeach
                <tr class="font-semibold">
                    <x-ui.td>{{ __('operations.accounting.combined_total') }}</x-ui.td>
                    <x-ui.td numeric>{{ $money($report['combined']['total_income']) }}</x-ui.td>
                    <x-ui.td numeric>{{ $money($report['combined']['total_expenses']) }}</x-ui.td>
                    <x-ui.td numeric>{{ $money($report['combined']['net_profit']) }}</x-ui.td>
                </tr>
            </x-ui.table>
        </div>

        <div class="mt-5 overflow-x-auto">
            <x-ui.table :caption="__('operations.accounting.combined_balance_sheet')">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('operations.accounting.business') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('operations.accounting.total_assets') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('operations.accounting.total_liabilities') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('operations.accounting.total_equity') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('operations.accounting.current_earnings') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('operations.accounting.difference') }}</x-ui.th>
                    </tr>
                </x-slot:head>
                @foreach($report['rows'] as $row)
                    <tr>
                        <x-ui.td><span class="font-semibold">{{ $row['business']->name }}</span></x-ui.td>
                        <x-ui.td numeric>{{ $money($row['balance_sheet']['total_assets']) }}</x-ui.td>
                        <x-ui.td numeric>{{ $money($row['balance_sheet']['total_liabilities']) }}</x-ui.td>
                        <x-ui.td numeric>{{ $money($row['balance_sheet']['total_equity']) }}</x-ui.td>
                        <x-ui.td numeric>{{ $money($row['balance_sheet']['current_earnings']) }}</x-ui.td>
                        <x-ui.td numeric>{{ $money($row['balance_sheet']['difference']) }}</x-ui.td>
                    </tr>
                @endforeach
                <tr class="font-semibold">
                    <x-ui.td>{{ __('operations.accounting.combined_total') }}</x-ui.td>
                    <x-ui.td numeric>{{ $money($report['combined']['total_assets']) }}</x-ui.td>
                    <x-ui.td numeric>{{ $money($report['combined']['total_liabilities']) }}</x-ui.td>
                    <x-ui.td numeric>{{ $money($report['combined']['total_equity']) }}</x-ui.td>
                    <x-ui.td numeric>{{ $money($report['combined']['current_earnings']) }}</x-ui.td>
                    <x-ui.td numeric>{{ $money($report['combined']['difference']) }}</x-ui.td>
                </tr>
            </x-ui.table>
        </div>
    </x-app.page>
@endsection
