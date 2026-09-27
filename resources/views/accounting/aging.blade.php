@extends('layouts.app')

@section('content')
    @php
        $formatter = app(\App\Support\LocalizedFormatter::class);
        $currency = app(\App\Services\CurrencyService::class)->baseCurrency();
        $money = fn (string $amount): string => $formatter->currency($amount, $currency);
        $labels = [
            'current' => 'Current',
            'days_1_30' => '1–30 days',
            'days_31_60' => '31–60 days',
            'days_61_90' => '61–90 days',
            'days_90_plus' => '90+ days',
        ];
    @endphp

    <x-app.page title="AR / AP Aging" subtitle="Outstanding receivables and payables by age as of a reporting date." icon="clock">
        <form method="GET" class="mb-5 flex max-w-md items-end gap-3">
            <div class="flex-1"><x-ui.input name="as_of" type="date" :value="$asOf" label="As of" /></div>
            <x-ui.button type="submit">Refresh</x-ui.button>
        </form>

        @foreach ([['Accounts Receivable', $receivables], ['Accounts Payable', $payables]] as [$title, $report])
            <div class="mb-6">
                <x-ui.card>
                    <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ $title }}</h2></x-slot:header>
                    <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
                        @foreach ($labels as $key => $label)
                            <x-ui.stat-card :title="$label" :value="$money($report['totals'][$key])" icon="calendar-days" />
                        @endforeach
                        <x-ui.stat-card title="Total" :value="$money($report['totals']['total'])" icon="banknotes" tone="brand" />
                    </div>
                </x-ui.card>
                <div class="mt-3">
                    <x-ui.table :caption="$title">
                        <x-slot:head>
                            <tr>
                                <x-ui.th>Party</x-ui.th><x-ui.th>Document</x-ui.th><x-ui.th>Document date</x-ui.th>
                                <x-ui.th>Due date</x-ui.th><x-ui.th numeric>Days overdue</x-ui.th><x-ui.th>Bucket</x-ui.th><x-ui.th numeric>Outstanding</x-ui.th>
                            </tr>
                        </x-slot:head>
                        @forelse ($report['rows'] as $row)
                            <tr>
                                <x-ui.td>{{ $row['party'] }}</x-ui.td><x-ui.td>{{ $row['document'] }}</x-ui.td>
                                <x-ui.td>{{ $formatter->date($row['document_date']) }}</x-ui.td>
                                <x-ui.td>{{ $formatter->date($row['due_date']) }}</x-ui.td>
                                <x-ui.td numeric>{{ $row['days_overdue'] }}</x-ui.td><x-ui.td>{{ $labels[$row['bucket']] }}</x-ui.td>
                                <x-ui.td numeric><span class="font-semibold">{{ $money($row['amount']) }}</span></x-ui.td>
                            </tr>
                        @empty
                            <tr><x-ui.td colspan="7">No outstanding balances.</x-ui.td></tr>
                        @endforelse
                    </x-ui.table>
                </div>
            </div>
        @endforeach
    </x-app.page>
@endsection
