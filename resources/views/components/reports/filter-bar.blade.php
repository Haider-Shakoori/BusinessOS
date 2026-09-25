@props([
    'reportType',
    'range',
    'dateFrom',
    'dateTo',
])

<x-ui.card bare>
    <form method="GET" action="{{ route('reports.index') }}" class="p-4" x-data="{ range: @js($range) }">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-ui.select name="report" :label="__('reports.report')" :value="$reportType">
                <option value="summary" @selected($reportType === 'summary')>{{ __('reports.sales_summary') }}</option>
                <option value="products" @selected($reportType === 'products')>{{ __('reports.sales_by_product') }}</option>
                <option value="customers" @selected($reportType === 'customers')>{{ __('reports.sales_by_customer') }}</option>
                <option value="expenses" @selected($reportType === 'expenses')>{{ __('reports.expense_report') }}</option>
                <option value="receivables" @selected($reportType === 'receivables')>{{ __('reports.receivables') }}</option>
            </x-ui.select>

            <x-ui.select name="range" :label="__('reports.range')" :value="$range" x-model="range">
                <option value="month" @selected($range === 'month')>{{ __('reports.this_month') }}</option>
                <option value="quarter" @selected($range === 'quarter')>{{ __('reports.this_quarter') }}</option>
                <option value="year" @selected($range === 'year')>{{ __('reports.this_year') }}</option>
                <option value="custom" @selected($range === 'custom')>{{ __('reports.custom') }}</option>
            </x-ui.select>

            <div x-show="range === 'custom'" x-cloak>
                <x-ui.input name="date_from" type="date" :label="__('reports.date_from')" :value="$dateFrom" />
            </div>

            <div x-show="range === 'custom'" x-cloak>
                <x-ui.input name="date_to" type="date" :label="__('reports.date_to')" :value="$dateTo" />
            </div>

            <div class="flex items-end gap-2">
                <x-ui.button type="submit" icon="chart-bar">{{ __('reports.apply') }}</x-ui.button>
                @if ($range !== 'month' || request()->filled('date_from') || request()->filled('date_to') || request()->filled('report'))
                    <x-ui.button variant="secondary" href="{{ route('reports.index') }}">{{ __('reports.clear') }}</x-ui.button>
                @endif
            </div>
        </div>
    </form>
</x-ui.card>
