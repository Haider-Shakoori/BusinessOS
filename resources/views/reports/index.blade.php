@extends('layouts.app')

@section('content')
    @php
        $money = fn (string $amount): string => $baseCurrency.' '.number_format((float) $amount, 2);
        $exportQuery = array_filter([
            'range' => $range,
            'date_from' => $range === 'custom' ? $dateFrom : null,
            'date_to' => $range === 'custom' ? $dateTo : null,
        ], fn ($value) => $value !== null && $value !== '');
        $reportTitle = match ($reportType) {
            'products' => __('reports.sales_by_product'),
            'customers' => __('reports.sales_by_customer'),
            'expenses' => __('reports.expense_report'),
            'receivables' => __('reports.receivables'),
            default => __('reports.sales_summary'),
        };
        $help = match ($reportType) {
            'products' => __('reports.product_help'),
            'customers' => __('reports.customer_help'),
            'expenses' => __('reports.expense_help'),
            'receivables' => __('reports.receivables_help'),
            default => __('reports.summary_help'),
        };
    @endphp

    <x-app.page
        :title="__('reports.title')"
        :subtitle="__('reports.subtitle')"
        icon="chart-bar"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('reports.title')],
        ]"
    >
        <x-slot:actions>
            <x-ui.button
                variant="secondary"
                icon="document-text"
                href="{{ route('reports.export', ['report' => $reportType] + $exportQuery) }}"
            >
                {{ __('reports.export_csv') }}
            </x-ui.button>
        </x-slot:actions>

        <x-reports.filter-bar
            :report-type="$reportType"
            :range="$range"
            :date-from="$dateFrom"
            :date-to="$dateTo"
        />

        <div class="mt-5 rounded-[10px] border border-slate-200/90 bg-white px-5 py-4 shadow-card dark:border-slate-700 dark:bg-slate-900">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-[14px] font-semibold text-slate-900 dark:text-white">{{ $reportTitle }}</h2>
                    <p class="mt-1 text-[11px] leading-4 text-slate-500 dark:text-slate-400">{{ $help }}</p>
                </div>
                <span class="mt-2 inline-flex w-fit rounded-full bg-slate-100 px-3 py-1 text-[11px] font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300 sm:mt-0">
                    {{ __('reports.period', ['from' => $dateFrom, 'to' => $dateTo]) }}
                </span>
            </div>
        </div>

        @if ($reportType === 'summary')
            <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                <x-ui.stat-card
                    :title="__('reports.sales')"
                    :value="$money($reportData['sales'])"
                    icon="chart-bar"
                    tone="brand"
                />
                <x-ui.stat-card
                    :title="__('reports.invoice_count')"
                    :value="number_format($reportData['invoice_count'])"
                    icon="receipt-percent"
                    tone="info"
                />
                <x-ui.stat-card
                    :title="__('reports.average_invoice')"
                    :value="$money($reportData['average_invoice'])"
                    icon="document-text"
                    tone="neutral"
                />
                <x-ui.stat-card
                    :title="__('reports.tax')"
                    :value="$money($reportData['tax'])"
                    icon="percent"
                    tone="warning"
                />
                <x-ui.stat-card
                    :title="__('reports.outstanding')"
                    :value="$money($reportData['receivables'])"
                    icon="banknotes"
                    tone="danger"
                />
            </div>
        @elseif ($reportType === 'products')
            <div class="mt-5">
                @if ($reportData['rows']->isEmpty())
                    <x-ui.card>
                        <x-ui.empty-state :title="__('reports.empty')" icon="chart-bar" />
                    </x-ui.card>
                @else
                    <x-ui.table :caption="$reportTitle">
                        <x-slot:head>
                            <tr>
                                <x-ui.th>{{ __('reports.product') }}</x-ui.th>
                                <x-ui.th>{{ __('reports.sku') }}</x-ui.th>
                                <x-ui.th numeric>{{ __('reports.quantity') }}</x-ui.th>
                                <x-ui.th numeric>{{ __('reports.invoice_count') }}</x-ui.th>
                                <x-ui.th numeric>{{ __('reports.sales') }}</x-ui.th>
                            </tr>
                        </x-slot:head>

                        @foreach ($reportData['rows'] as $row)
                            <tr>
                                <x-ui.td><span class="font-semibold text-slate-900 dark:text-white">{{ $row['name'] }}</span></x-ui.td>
                                <x-ui.td>{{ $row['sku'] ?: '—' }}</x-ui.td>
                                <x-ui.td numeric>{{ $row['quantity'] }}</x-ui.td>
                                <x-ui.td numeric>{{ $row['invoice_count'] }}</x-ui.td>
                                <x-ui.td numeric><span class="font-semibold">{{ $money($row['sales']) }}</span></x-ui.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </div>
        @elseif ($reportType === 'customers')
            <div class="mt-5">
                @if ($reportData['rows']->isEmpty())
                    <x-ui.card>
                        <x-ui.empty-state :title="__('reports.empty')" icon="users" />
                    </x-ui.card>
                @else
                    <x-ui.table :caption="$reportTitle">
                        <x-slot:head>
                            <tr>
                                <x-ui.th>{{ __('reports.customer') }}</x-ui.th>
                                <x-ui.th>{{ __('reports.company') }}</x-ui.th>
                                <x-ui.th numeric>{{ __('reports.invoice_count') }}</x-ui.th>
                                <x-ui.th numeric>{{ __('reports.sales') }}</x-ui.th>
                                <x-ui.th numeric>{{ __('reports.outstanding') }}</x-ui.th>
                            </tr>
                        </x-slot:head>

                        @foreach ($reportData['rows'] as $row)
                            <tr>
                                <x-ui.td><span class="font-semibold text-slate-900 dark:text-white">{{ $row['name'] }}</span></x-ui.td>
                                <x-ui.td>{{ $row['company_name'] ?: '—' }}</x-ui.td>
                                <x-ui.td numeric>{{ $row['invoice_count'] }}</x-ui.td>
                                <x-ui.td numeric><span class="font-semibold">{{ $money($row['sales']) }}</span></x-ui.td>
                                <x-ui.td numeric>{{ $money($row['receivables']) }}</x-ui.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </div>
        @elseif ($reportType === 'expenses')
            <div class="mt-5">
                @if ($reportData['rows']->isEmpty())
                    <x-ui.card>
                        <x-ui.empty-state :title="__('reports.empty')" icon="arrow-trending-down" />
                    </x-ui.card>
                @else
                    <x-ui.table :caption="$reportTitle">
                        <x-slot:head>
                            <tr>
                                <x-ui.th>{{ __('reports.category') }}</x-ui.th>
                                <x-ui.th numeric>{{ __('reports.expense_count') }}</x-ui.th>
                                <x-ui.th numeric>{{ __('reports.amount') }}</x-ui.th>
                            </tr>
                        </x-slot:head>

                        @foreach ($reportData['rows'] as $row)
                            <tr>
                                <x-ui.td><span class="font-semibold text-slate-900 dark:text-white">{{ $row['name'] }}</span></x-ui.td>
                                <x-ui.td numeric>{{ $row['count'] }}</x-ui.td>
                                <x-ui.td numeric><span class="font-semibold">{{ $money($row['amount']) }}</span></x-ui.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </div>
        @elseif ($reportType === 'receivables')
            <div class="mt-5">
                @if ($reportData['rows']->isEmpty())
                    <x-ui.card>
                        <x-ui.empty-state :title="__('reports.empty')" icon="banknotes" />
                    </x-ui.card>
                @else
                    <x-ui.table :caption="$reportTitle">
                        <x-slot:head>
                            <tr>
                                <x-ui.th>{{ __('reports.invoice') }}</x-ui.th>
                                <x-ui.th>{{ __('reports.date') }}</x-ui.th>
                                <x-ui.th>{{ __('reports.customer') }}</x-ui.th>
                                <x-ui.th numeric>{{ __('reports.invoice_total') }}</x-ui.th>
                                <x-ui.th numeric>{{ __('reports.amount_due') }}</x-ui.th>
                            </tr>
                        </x-slot:head>

                        @foreach ($reportData['rows'] as $row)
                            <tr>
                                <x-ui.td>
                                    <a href="{{ route('invoices.show', $row['invoice_id']) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">
                                        {{ $row['invoice_number'] }}
                                    </a>
                                </x-ui.td>
                                <x-ui.td>{{ $row['date'] }}</x-ui.td>
                                <x-ui.td>{{ $row['customer'] }}</x-ui.td>
                                <x-ui.td numeric>{{ $money($row['invoice_total']) }}</x-ui.td>
                                <x-ui.td numeric><span class="font-semibold text-red-600 dark:text-red-400">{{ $money($row['amount_due']) }}</span></x-ui.td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </div>
        @endif
    </x-app.page>
@endsection
