@extends('layouts.app')

@section('content')
    @php
        $summary = $summary ?? [];
        $rows = $rows ?? [];
        $searchTerm = $searchTerm ?? '';
        $type = $type ?? null;
        $dateFrom = $dateFrom ?? null;
        $dateTo = $dateTo ?? null;
        $hasFilters = $searchTerm !== '' || $type !== null || $dateFrom !== null || $dateTo !== null;

        $periodDebits = '0.0000';
        $periodCredits = '0.0000';
        foreach ($rows as $row) {
            if (($row['type'] ?? '') === 'brought_forward') {
                continue;
            }
            $periodDebits = \App\Support\Decimal::add($periodDebits, $row['base_debit'] ?? $row['debit']);
            $periodCredits = \App\Support\Decimal::add($periodCredits, $row['base_credit'] ?? $row['credit']);
        }

        $showAmount = static fn (?string $value): string => $value && ! \App\Support\Decimal::eq($value, '0') ? $value : '—';
    @endphp

    <x-app.page
        :title="__('customers.ledger.title')"
        :subtitle="__('customers.ledger.subtitle', ['name' => $customer->name])"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('customers.title'), 'url' => route('customers.index')],
            ['label' => $customer->name, 'url' => route('customers.show', $customer)],
            ['label' => __('customers.ledger.title')],
        ]"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('customers.show', $customer) }}" icon="eye">
                {{ __('customers.ledger.view_customer') }}
            </x-ui.button>
            <x-ui.button variant="secondary" href="{{ route('customers.statement', $customer) }}" icon="document-text">
                {{ __('customers.statement.title') }}
            </x-ui.button>
        </x-slot:actions>

        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        <x-ui.card>
            <form method="GET" action="{{ route('customers.ledger', $customer) }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <x-ui.input
                        name="date_from"
                        type="date"
                        :label="__('customers.ledger.date_from')"
                        :value="$dateFrom"
                    />
                </div>

                <div>
                    <x-ui.input
                        name="date_to"
                        type="date"
                        :label="__('customers.ledger.date_to')"
                        :value="$dateTo"
                    />
                </div>

                <x-ui.select
                    name="type"
                    :label="__('customers.ledger.type_filter')"
                    :placeholder="__('customers.ledger.all_types')"
                    :value="$type"
                >
                    <option value="invoice" @selected($type === 'invoice')>{{ __('customers.ledger.only_invoices') }}</option>
                    <option value="payment" @selected($type === 'payment')>{{ __('customers.ledger.only_payments') }}</option>
                </x-ui.select>

                <div>
                    <x-ui.input
                        name="search"
                        :label="__('customers.ledger.search')"
                        :placeholder="__('customers.ledger.search_placeholder')"
                        :value="$searchTerm"
                    />
                </div>

                <div class="flex flex-wrap items-end gap-2 sm:col-span-2 lg:col-span-4">
                    <x-ui.button type="submit" icon="search">{{ __('customers.ledger.apply') }}</x-ui.button>
                    @if ($hasFilters)
                        <x-ui.button variant="secondary" href="{{ route('customers.ledger', $customer) }}">
                            {{ __('actions.clear') }}
                        </x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>

        <p class="mt-6 flex items-start gap-1.5 text-xs text-gray-500 dark:text-gray-400">
            <x-ui.icon name="info-circle" class="mt-px size-4 shrink-0" />
            {{ __('customers.ledger.base_currency_note', ['currency' => $baseCurrency]) }}
        </p>

        <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card
                :title="__('customers.ledger.outstanding_balance')"
                :value="$summary['outstanding_balance'] ?? '0'"
                icon="banknotes"
                :tone="\App\Support\Decimal::gt($summary['outstanding_balance'] ?? '0', '0') ? 'warning' : 'success'"
            />
            <x-ui.stat-card
                :title="__('customers.ledger.total_invoiced')"
                :value="$summary['total_invoiced'] ?? '0'"
                icon="receipt-percent"
                tone="info"
            />
            <x-ui.stat-card
                :title="__('customers.ledger.total_paid')"
                :value="$summary['total_paid'] ?? '0'"
                icon="check-circle"
                tone="brand"
            />
            <x-ui.stat-card
                :title="__('customers.ledger.opening')"
                :value="$summary['opening_balance'] ?? '0'"
                icon="clock"
                tone="neutral"
            />
        </div>

        @if (empty($rows))
            <x-ui.card>
                <x-ui.empty-state
                    :title="__('customers.ledger.no_transactions')"
                    :description="__('customers.ledger.no_transactions_description')"
                    icon="receipt-percent"
                />
            </x-ui.card>
        @else
            <x-ui.table :caption="__('customers.ledger.title')">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('customers.ledger.columns.date') }}</x-ui.th>
                        <x-ui.th>{{ __('customers.ledger.columns.type') }}</x-ui.th>
                        <x-ui.th>{{ __('customers.ledger.columns.reference') }}</x-ui.th>
                        <x-ui.th>{{ __('customers.ledger.columns.description') }}</x-ui.th>
                        <x-ui.th class="text-end">{{ __('customers.ledger.columns.debit') }}</x-ui.th>
                        <x-ui.th class="text-end">{{ __('customers.ledger.columns.credit') }}</x-ui.th>
                        @if ($showRunningBalance)
                            <x-ui.th class="text-end">{{ __('customers.ledger.columns.balance') }}</x-ui.th>
                        @endif
                    </tr>
                </x-slot:head>

                @foreach ($rows as $row)
                    @if ($row['type'] === 'brought_forward')
                        <tr class="bg-gray-50 dark:bg-gray-700/40">
                            <x-ui.td colspan="4">
                                <span class="font-semibold text-gray-700 dark:text-gray-200">{{ __('customers.ledger.types.brought_forward') }}</span>
                            </x-ui.td>
                            <x-ui.td class="text-end">
                                <span class="whitespace-nowrap font-medium tabular-nums text-gray-700 dark:text-gray-200" dir="ltr">{{ $showAmount($row['debit']) }}</span>
                            </x-ui.td>
                            <x-ui.td class="text-end">
                                <span class="whitespace-nowrap tabular-nums text-gray-500 dark:text-gray-400" dir="ltr">{{ $showAmount($row['credit']) }}</span>
                            </x-ui.td>
                            @if ($showRunningBalance)
                                <x-ui.td class="text-end">
                                    <span class="whitespace-nowrap font-medium tabular-nums text-gray-700 dark:text-gray-200" dir="ltr">{{ $row['balance'] }}</span>
                                </x-ui.td>
                            @endif
                        </tr>
                    @else
                        <tr @if ($row['reversed']) class="opacity-60 dark:opacity-50" @endif>
                            <x-ui.td>
                                <span class="whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $row['date'] ?: '—' }}</span>
                            </x-ui.td>
                            <x-ui.td>
                                <span class="whitespace-nowrap">{{ __('customers.ledger.types.' . $row['type']) }}</span>
                                @if ($row['reversed'])
                                    <span class="ms-1 inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                        {{ __('customers.ledger.reversed') }}
                                    </span>
                                @endif
                            </x-ui.td>
                            <x-ui.td>
                                @if ($row['model_id'] !== null && $row['model_type'] === 'invoice' && auth()->user()->can('invoices.view'))
                                    <a
                                        href="{{ route('invoices.show', $row['model_id']) }}"
                                        class="whitespace-nowrap font-semibold text-gray-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                                    >
                                        {{ $row['reference'] }}
                                    </a>
                                @elseif ($row['model_id'] !== null && $row['model_type'] === 'payment' && auth()->user()->can('payments.view'))
                                    <a
                                        href="{{ route('payments.show', $row['model_id']) }}"
                                        class="whitespace-nowrap font-semibold text-gray-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                                    >
                                        {{ $row['reference'] }}
                                    </a>
                                @elseif ($row['reference'] !== '')
                                    <span class="whitespace-nowrap font-semibold text-gray-900 dark:text-white">{{ $row['reference'] }}</span>
                                @else
                                    <span class="text-gray-400 dark:text-gray-500">—</span>
                                @endif
                            </x-ui.td>
                            <x-ui.td>
                                <span class="text-gray-500 dark:text-gray-400">{{ $row['description'] ?: '—' }}</span>
                            </x-ui.td>
                            <x-ui.td class="text-end">
                                <span class="whitespace-nowrap font-medium tabular-nums text-gray-900 dark:text-white" dir="ltr">
                                    {{ $showAmount($row['debit']) }}
                                    @if ($row['currency_code'] ?? null)
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $row['currency_code'] }}</span>
                                    @endif
                                </span>
                            </x-ui.td>
                            <x-ui.td class="text-end">
                                <span class="whitespace-nowrap font-medium tabular-nums text-gray-900 dark:text-white" dir="ltr">
                                    {{ $showAmount($row['credit']) }}
                                    @if ($row['currency_code'] ?? null)
                                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $row['currency_code'] }}</span>
                                    @endif
                                </span>
                            </x-ui.td>
                            @if ($showRunningBalance)
                                <x-ui.td class="text-end">
                                    <span class="whitespace-nowrap font-medium tabular-nums text-gray-700 dark:text-gray-200" dir="ltr">{{ $row['balance'] }}</span>
                                </x-ui.td>
                            @endif
                        </tr>
                    @endif
                @endforeach

                <x-slot:footer>
                    <div class="grid gap-4 border-t border-gray-200 px-4 py-3 sm:grid-cols-2 dark:border-gray-700">
                        <dl class="space-y-1">
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('customers.statement.debits_total') }}</dt>
                                <dd class="whitespace-nowrap font-medium tabular-nums text-gray-900 dark:text-white" dir="ltr">{{ $periodDebits }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-4 text-sm">
                                <dt class="text-gray-500 dark:text-gray-400">{{ __('customers.statement.credits_total') }}</dt>
                                <dd class="whitespace-nowrap font-medium tabular-nums text-gray-900 dark:text-white" dir="ltr">{{ $periodCredits }}</dd>
                            </div>
                        </dl>
                        <div class="flex items-end justify-end">
                            <div class="text-sm sm:text-right">
                                <dt class="text-gray-500 dark:text-gray-400">{{ $hasPeriodFilter ? __('customers.ledger.period_closing_balance') : __('customers.ledger.closing_balance') }}</dt>
                                <dd class="mt-0.5 whitespace-nowrap text-base font-bold tabular-nums text-gray-900 dark:text-white" dir="ltr">{{ $closingBalance }}</dd>
                                @if ($hasPeriodFilter)
                                    <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ __('customers.ledger.outstanding_balance') }} — {{ $summary['outstanding_balance'] ?? '0' }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </x-slot:footer>
            </x-ui.table>

            @if (! $showRunningBalance)
                <p class="mt-3 flex items-start gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                    <x-ui.icon name="info-circle" class="mt-px size-4 shrink-0" />
                    {{ __('customers.ledger.filtered_note') }}
                </p>
            @endif
        @endif
    </x-app.page>
@endsection