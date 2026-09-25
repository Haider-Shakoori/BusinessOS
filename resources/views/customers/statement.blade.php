@extends('layouts.app')

@section('content')
    @php
        $rows = $rows ?? [];
        $businessDetails = $businessDetails ?? [];
        $dateFrom = $dateFrom ?? null;
        $dateTo = $dateTo ?? null;

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

    <x-app.page icon="users"
        :title="__('customers.statement.title')"
        :subtitle="__('customers.statement.subtitle', ['name' => $customer->name])"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('customers.title'), 'url' => route('customers.index')],
            ['label' => $customer->name, 'url' => route('customers.show', $customer)],
            ['label' => __('customers.statement.title')],
        ]"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('customers.ledger', $customer) }}" icon="chart-bar">
                {{ __('customers.statement.ledger_link') }}
            </x-ui.button>
            <x-ui.button variant="secondary" icon="document-text" x-on:click="window.print()" class="no-print">
                {{ __('customers.statement.print') }}
            </x-ui.button>
        </x-slot:actions>

        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-card sm:p-10 dark:border-slate-700 dark:bg-slate-800 print:rounded-none print:border-0 print:shadow-none print:p-0">
            {{-- Statement header --}}
            <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-6 print:border-slate-300">
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-slate-900 dark:text-white print:text-slate-900">{{ $business?->name ?: config('app.name') }}</h2>
                    @if (! empty($businessDetails['address']) || ! empty($businessDetails['phone']) || ! empty($businessDetails['email']))
                        <address class="mt-1 not-italic text-sm text-slate-500 dark:text-slate-400 print:text-slate-600">
                            @if (! empty($businessDetails['address']))
                                <span class="block whitespace-pre-line">{{ $businessDetails['address'] }}</span>
                            @endif
                            @if (! empty($businessDetails['phone']))
                                <span class="block">{{ $businessDetails['phone'] }}</span>
                            @endif
                            @if (! empty($businessDetails['email']))
                                <span class="block">{{ $businessDetails['email'] }}</span>
                            @endif
                        </address>
                    @endif
                </div>

                <div class="text-start sm:text-end">
                    <p class="text-lg font-semibold text-slate-900 dark:text-white print:text-slate-900">{{ __('customers.statement.for') }}</p>
                    <p class="mt-1 text-sm font-medium text-slate-700 dark:text-slate-200 print:text-slate-800">{{ $customer->name }}</p>
                    <div class="mt-1 text-sm text-slate-500 dark:text-slate-400 print:text-slate-600">
                        @if ($customer->company_name)
                            <p>{{ $customer->company_name }}</p>
                        @endif
                        @if ($customer->email)
                            <p dir="ltr">{{ $customer->email }}</p>
                        @endif
                        @if ($customer->phone)
                            <p dir="ltr">{{ $customer->phone }}</p>
                        @endif
                        @if ($customer->address)
                            <p class="whitespace-pre-line">{{ $customer->address }}</p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Period --}}
            <div class="mt-6 flex flex-wrap items-center justify-between gap-2 text-sm">
                <p class="font-medium text-slate-700 dark:text-slate-200 print:text-slate-800">
                    {{ __('customers.statement.period') }}:
                    <span class="font-semibold">
                        {{ $dateFrom ?: __('customers.statement.all_dates') }} — {{ $dateTo ?: __('customers.statement.all_dates') }}
                    </span>
                    <span class="ms-2 font-medium text-slate-500 dark:text-slate-400 print:text-slate-600">({{ $baseCurrency }})</span>
                </p>
                <p class="text-slate-500 dark:text-slate-400 print:text-slate-600">
                    {{ __('customers.statement.as_of') }} {{ now()->format('Y-m-d') }}
                </p>
            </div>

            {{-- Statement table --}}
            <div class="mt-6 overflow-x-auto">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b-2 border-slate-200 text-start text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-300 dark:text-slate-400">
                            <th class="py-2 pe-3 text-start">{{ __('customers.ledger.columns.date') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('customers.ledger.columns.type') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('customers.ledger.columns.reference') }}</th>
                            <th class="py-2 pe-3 text-start">{{ __('customers.ledger.columns.description') }}</th>
                            <th class="py-2 pe-3 text-end">{{ __('customers.ledger.columns.debit') }}</th>
                            <th class="py-2 pe-3 text-end">{{ __('customers.ledger.columns.credit') }}</th>
                            <th class="py-2 text-end">{{ __('customers.ledger.columns.balance') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rows as $row)
                            @if ($row['type'] === 'brought_forward')
                                <tr class="border-b border-slate-200 bg-slate-50 print:bg-slate-100">
                                    <td colspan="4" class="py-2 pe-3 font-semibold text-slate-700 print:text-slate-800">{{ __('customers.ledger.types.brought_forward') }}</td>
                                    <td class="py-2 pe-3 text-end"><span class="whitespace-nowrap font-medium tabular-nums" dir="ltr">{{ $showAmount($row['debit']) }}</span></td>
                                    <td class="py-2 pe-3 text-end"><span class="whitespace-nowrap tabular-nums" dir="ltr">{{ $showAmount($row['credit']) }}</span></td>
                                    <td class="py-2 text-end"><span class="whitespace-nowrap font-medium tabular-nums" dir="ltr">{{ $row['balance'] }}</span></td>
                                </tr>
                            @else
                                <tr class="border-b border-slate-200 print:border-slate-300 @if ($row['reversed']) opacity-60 dark:opacity-50 @endif">
                                    <td class="py-2 pe-3 whitespace-nowrap">{{ $row['date'] ?: '—' }}</td>
                                    <td class="py-2 pe-3 whitespace-nowrap">
                                        {{ __('customers.ledger.types.' . $row['type']) }}
                                        @if ($row['reversed'])
                                            <span class="ms-1 text-xs font-medium text-slate-500 dark:text-slate-400">({{ __('customers.ledger.reversed') }})</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pe-3 whitespace-nowrap font-medium">{{ $row['reference'] ?: '—' }}</td>
                                    <td class="py-2 pe-3">{{ $row['description'] ?: '—' }}</td>
                                    <td class="py-2 pe-3 text-end"><span class="whitespace-nowrap tabular-nums" dir="ltr">{{ $showAmount($row['debit']) }}</span></td>
                                    <td class="py-2 pe-3 text-end"><span class="whitespace-nowrap tabular-nums" dir="ltr">{{ $showAmount($row['credit']) }}</span></td>
                                    <td class="py-2 text-end"><span class="whitespace-nowrap font-medium tabular-nums" dir="ltr">{{ $row['balance'] }}</span></td>
                                </tr>
                            @endif
                        @empty
                            <tr class="border-b border-slate-200 print:border-slate-300">
                                <td colspan="7" class="py-6 text-center text-slate-500 dark:text-slate-400">{{ __('customers.ledger.no_transactions') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Totals --}}
            <dl class="mt-6 grid gap-2 sm:grid-cols-2 sm:gap-4">
                <div class="space-y-1 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400 print:text-slate-600">{{ $broughtForward !== null ? __('customers.statement.brought_forward') : __('customers.statement.opening') }}</dt>
                        <dd class="whitespace-nowrap font-medium tabular-nums" dir="ltr">{{ $broughtForward ?? $openingBalance }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400 print:text-slate-600">{{ __('customers.statement.debits_total') }}</dt>
                        <dd class="whitespace-nowrap font-medium tabular-nums" dir="ltr">{{ $periodDebits }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-slate-500 dark:text-slate-400 print:text-slate-600">{{ __('customers.statement.credits_total') }}</dt>
                        <dd class="whitespace-nowrap font-medium tabular-nums" dir="ltr">{{ $periodCredits }}</dd>
                    </div>
                </div>
                <div class="flex items-end justify-end sm:text-end">
                    <div class="rounded-[7px] bg-slate-50 px-4 py-3 dark:bg-slate-700/50 print:bg-slate-100">
                        <dt class="text-sm font-medium text-slate-500 dark:text-slate-400 print:text-slate-600">{{ __('customers.statement.closing_balance') }}</dt>
                        <dd class="mt-0.5 text-2xl font-bold tabular-nums text-slate-900 dark:text-white print:text-slate-900" dir="ltr">{{ $closingBalance }}</dd>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 print:text-slate-600">{{ __('customers.statement.outstanding_balance') }}</p>
                    </div>
                </div>
            </dl>

            <p class="mt-8 border-t border-slate-200 pt-4 text-xs text-slate-400 dark:text-slate-500 print:border-slate-300 print:text-slate-500">
                {{ __('customers.statement.prepared_on') }} {{ now()->format('Y-m-d H:i') }}
            </p>
        </div>
    </x-app.page>
@endsection