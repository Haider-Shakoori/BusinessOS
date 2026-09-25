@extends('layouts.app')

@section('content')
    @php
        $baseCurrency = $dashboard['base_currency'];
        $money = fn (string $amount): string => $baseCurrency.' '.number_format((float) $amount, 2);
        $hasMetrics = collect(['sales', 'revenue', 'expenses', 'receivables'])
            ->contains(fn (string $key): bool => (bool) ($visibility[$key] ?? false));
        $hasActivitySources = ($visibility['recent_invoices'] ?? false)
            || ($visibility['recent_payments'] ?? false)
            || ($visibility['recent_expenses'] ?? false);
    @endphp

    <x-app.page
        :title="__('dashboard.title')"
        :subtitle="__('dashboard.subtitle', ['business' => $business?->name ?? ''])"
        icon="chart-bar"
        :breadcrumbs="[['label' => __('modules.dashboard')]]"
    >
        @if (session('status'))
            <div class="mb-5">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        <div class="mb-5 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <p class="text-[13px] font-medium text-slate-700 dark:text-slate-300">
                    {{ __('dashboard.welcome', ['name' => auth()->user()->name]) }}
                </p>
                <p class="mt-1 text-[12px] text-slate-500 dark:text-slate-400">
                    {{ __('dashboard.period_label', ['from' => $dateFrom, 'to' => $dateTo]) }}
                </p>
            </div>

            @if ($business)
                <span class="inline-flex w-fit shrink-0 items-center gap-2 rounded-full border border-brand-100 bg-brand-50 px-3 py-1.5 text-[11px] font-medium text-brand-700 dark:border-brand-500/20 dark:bg-brand-500/10 dark:text-brand-300">
                    <x-ui.icon name="briefcase" class="size-3.5" />
                    {{ __('business.current_business') }}: {{ $business->name }}
                </span>
            @endif
        </div>

        <x-ui.card bare>
            <form
                method="GET"
                action="{{ route('app.home') }}"
                class="p-4"
                x-data="{ range: @js($range) }"
            >
                <div class="flex flex-col gap-4 xl:flex-row xl:items-end">
                    <div class="min-w-0 flex-1">
                        <div class="mb-3 flex items-center gap-2">
                            <span class="grid size-8 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                                <x-ui.icon name="clock" class="size-4" />
                            </span>
                            <div>
                                <h2 class="text-[13px] font-semibold text-slate-900 dark:text-white">{{ __('dashboard.period') }}</h2>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400">{{ __('dashboard.period_label', ['from' => $dateFrom, 'to' => $dateTo]) }}</p>
                            </div>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            <x-ui.select name="range" :label="__('dashboard.range')" :value="$range" x-model="range">
                                <option value="month" @selected($range === 'month')>{{ __('dashboard.this_month') }}</option>
                                <option value="quarter" @selected($range === 'quarter')>{{ __('dashboard.this_quarter') }}</option>
                                <option value="year" @selected($range === 'year')>{{ __('dashboard.this_year') }}</option>
                                <option value="custom" @selected($range === 'custom')>{{ __('dashboard.custom') }}</option>
                            </x-ui.select>

                            <div x-show="range === 'custom'" x-cloak>
                                <x-ui.input name="date_from" type="date" :label="__('dashboard.date_from')" :value="$dateFrom" />
                            </div>

                            <div x-show="range === 'custom'" x-cloak>
                                <x-ui.input name="date_to" type="date" :label="__('dashboard.date_to')" :value="$dateTo" />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-2 xl:pb-0.5">
                        <x-ui.button type="submit" icon="search">{{ __('dashboard.apply') }}</x-ui.button>
                    </div>
                </div>
            </form>
        </x-ui.card>

        @if ($hasMetrics)
            <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @if ($visibility['sales'] ?? false)
                    <x-ui.stat-card
                        :title="__('dashboard.sales')"
                        :value="$money($dashboard['metrics']['sales']['amount'])"
                        :hint="__('dashboard.sales_hint', ['count' => $dashboard['metrics']['sales']['count']])"
                        icon="chart-bar"
                        tone="brand"
                    />
                @endif

                @if ($visibility['revenue'] ?? false)
                    <x-ui.stat-card
                        :title="__('dashboard.revenue')"
                        :value="$money($dashboard['metrics']['revenue']['amount'])"
                        :hint="__('dashboard.revenue_hint', ['count' => $dashboard['metrics']['revenue']['count']])"
                        icon="banknotes"
                        tone="success"
                    />
                @endif

                @if ($visibility['expenses'] ?? false)
                    <x-ui.stat-card
                        :title="__('dashboard.expenses')"
                        :value="$money($dashboard['metrics']['expenses']['amount'])"
                        :hint="__('dashboard.expenses_hint', ['count' => $dashboard['metrics']['expenses']['count']])"
                        icon="arrow-trending-down"
                        tone="warning"
                    />
                @endif

                @if ($visibility['receivables'] ?? false)
                    <x-ui.stat-card
                        :title="__('dashboard.receivables')"
                        :value="$money($dashboard['metrics']['receivables']['amount'])"
                        :hint="__('dashboard.receivables_hint', ['count' => $dashboard['metrics']['receivables']['count']])"
                        icon="receipt-percent"
                        tone="danger"
                        value-class="text-red-600 dark:text-red-400"
                    />
                @endif
            </div>
        @else
            <x-ui.card class="mt-5">
                <x-ui.empty-state
                    :title="__('dashboard.no_widgets')"
                    :description="__('dashboard.no_widgets_help')"
                    icon="chart-bar"
                />
            </x-ui.card>
        @endif

        <div class="mt-5 grid gap-5 xl:grid-cols-12">
            @if ($visibility['top_customers'] ?? false)
                <x-ui.card bare class="xl:col-span-5">
                    <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                        <div>
                            <h2 class="text-[14px] font-semibold text-slate-900 dark:text-white">{{ __('dashboard.top_customers') }}</h2>
                            <p class="mt-0.5 text-[11px] leading-4 text-slate-500 dark:text-slate-400">{{ __('dashboard.top_customers_help') }}</p>
                        </div>
                        <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                            <x-ui.icon name="users" class="size-[18px]" />
                        </span>
                    </div>

                    @if ($dashboard['top_customers']->isEmpty())
                        <div class="px-5 py-10 text-center text-[12px] text-slate-500 dark:text-slate-400">
                            {{ __('dashboard.no_customer_data') }}
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full">
                                <thead class="bg-slate-50/90 dark:bg-slate-800/70">
                                    <tr>
                                        <x-ui.th>{{ __('dashboard.customer') }}</x-ui.th>
                                        <x-ui.th numeric>{{ __('dashboard.invoices') }}</x-ui.th>
                                        <x-ui.th numeric>{{ __('dashboard.sales_value') }}</x-ui.th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                    @foreach ($dashboard['top_customers'] as $customer)
                                        <tr class="transition-colors hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                            <x-ui.td>
                                                <div class="flex min-w-0 items-center gap-2.5">
                                                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand-50 text-[11px] font-semibold text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                                                        {{ mb_strtoupper(mb_substr($customer['name'], 0, 1)) }}
                                                    </span>
                                                    <span class="min-w-0">
                                                        @if ($customer['url'])
                                                            <a href="{{ $customer['url'] }}" class="block truncate font-semibold text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">
                                                                {{ $customer['name'] }}
                                                            </a>
                                                        @else
                                                            <span class="block truncate font-semibold text-slate-900 dark:text-white">{{ $customer['name'] }}</span>
                                                        @endif
                                                        @if ($customer['company_name'])
                                                            <span class="block truncate text-[10px] text-slate-500 dark:text-slate-400">{{ $customer['company_name'] }}</span>
                                                        @endif
                                                    </span>
                                                </div>
                                            </x-ui.td>
                                            <x-ui.td numeric>{{ $customer['invoice_count'] }}</x-ui.td>
                                            <x-ui.td numeric>
                                                <span class="whitespace-nowrap font-semibold">{{ $money($customer['amount']) }}</span>
                                            </x-ui.td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            @if ($hasActivitySources)
                <x-ui.card bare class="{{ ($visibility['top_customers'] ?? false) ? 'xl:col-span-7' : 'xl:col-span-12' }}">
                    <div class="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                        <div>
                            <h2 class="text-[14px] font-semibold text-slate-900 dark:text-white">{{ __('dashboard.recent_activity') }}</h2>
                            <p class="mt-0.5 text-[11px] leading-4 text-slate-500 dark:text-slate-400">{{ __('dashboard.recent_activity_help') }}</p>
                        </div>
                        <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                            <x-ui.icon name="clock" class="size-[18px]" />
                        </span>
                    </div>

                    @if ($dashboard['recent_activity']->isEmpty())
                        <div class="px-5 py-10 text-center text-[12px] text-slate-500 dark:text-slate-400">
                            {{ __('dashboard.no_activity') }}
                        </div>
                    @else
                        <div class="divide-y divide-slate-100 dark:divide-slate-800">
                            @foreach ($dashboard['recent_activity'] as $activity)
                                @php
                                    $activityMeta = match ($activity['type']) {
                                        'payment' => ['banknotes', 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400'],
                                        'expense' => ['arrow-trending-down', 'bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-400'],
                                        default => ['document-text', 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400'],
                                    };
                                    $statusTone = match ($activity['status']) {
                                        'paid', 'received' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
                                        'reversed' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
                                        'partially_paid' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
                                        'draft' => 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
                                        default => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
                                    };
                                    $activityStatus = __('dashboard.activity.'.$activity['status']);
                                    if ($activityStatus === 'dashboard.activity.'.$activity['status']) {
                                        $activityStatus = __('invoices.statuses.'.$activity['status']);
                                    }
                                @endphp

                                <a href="{{ $activity['url'] }}" class="flex items-center gap-3 px-5 py-3.5 transition-colors hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-lg {{ $activityMeta[1] }}">
                                        <x-ui.icon :name="$activityMeta[0]" class="size-[17px]" />
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span class="text-[12.5px] font-semibold text-slate-900 dark:text-white">{{ __('dashboard.activity.'.$activity['type']) }}</span>
                                            <span class="font-mono text-[11px] text-slate-500 dark:text-slate-400">{{ $activity['reference'] }}</span>
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium {{ $statusTone }}">{{ $activityStatus }}</span>
                                        </span>
                                        <span class="mt-0.5 block truncate text-[11px] text-slate-500 dark:text-slate-400">
                                            {{ $activity['party'] ?: '—' }} · {{ $activity['date'] }}
                                        </span>
                                    </span>

                                    <span class="shrink-0 whitespace-nowrap text-[12px] font-semibold tabular-nums text-slate-900 dark:text-white">
                                        {{ $money($activity['amount']) }}
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </x-ui.card>
            @endif
        </div>

        <details class="mt-5 overflow-hidden rounded-[10px] border border-slate-200/90 bg-white shadow-card dark:border-slate-700 dark:bg-slate-900">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4">
                <span class="flex items-center gap-3">
                    <span class="grid size-9 place-items-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        <x-ui.icon name="shield-check" class="size-[18px]" />
                    </span>
                    <span>
                        <span class="block text-[13px] font-semibold text-slate-900 dark:text-white">{{ __('dashboard.access') }}</span>
                        <span class="mt-0.5 block text-[11px] text-slate-500 dark:text-slate-400">{{ __('dashboard.access_help') }}</span>
                    </span>
                </span>
                <x-ui.icon name="chevron-down" class="size-4 text-slate-400" />
            </summary>

            <div class="border-t border-slate-200 px-5 py-4 dark:border-slate-700">
                <div class="flex flex-wrap gap-2">
                    @forelse ($roles as $role)
                        @php
                            $roleKey = 'authorization.role_'.$role->slug;
                            $roleLabel = __($roleKey);
                            if ($roleLabel === $roleKey) {
                                $roleLabel = $role->name;
                            }
                        @endphp
                        <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-[11px] font-medium text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                            <x-ui.icon name="shield-check" class="size-3.5" />
                            {{ $roleLabel }}
                        </span>
                    @empty
                        <span class="text-[12px] text-slate-500 dark:text-slate-400">{{ __('authorization.no_roles') }}</span>
                    @endforelse
                </div>

                <ul class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    @can('users.view')
                        <li class="flex items-center gap-2 text-[12px] text-slate-600 dark:text-slate-300"><x-ui.icon name="check-circle" class="size-4 text-brand-600 dark:text-brand-400" />{{ __('authorization.permission_users_view') }}</li>
                    @endcan
                    @can('users.manage')
                        <li class="flex items-center gap-2 text-[12px] text-slate-600 dark:text-slate-300"><x-ui.icon name="check-circle" class="size-4 text-brand-600 dark:text-brand-400" />{{ __('authorization.permission_users_manage') }}</li>
                    @endcan
                    @can('settings.view')
                        <li class="flex items-center gap-2 text-[12px] text-slate-600 dark:text-slate-300"><x-ui.icon name="check-circle" class="size-4 text-brand-600 dark:text-brand-400" />{{ __('authorization.permission_settings_view') }}</li>
                    @endcan
                    @can('settings.manage')
                        <li class="flex items-center gap-2 text-[12px] text-slate-600 dark:text-slate-300"><x-ui.icon name="check-circle" class="size-4 text-brand-600 dark:text-brand-400" />{{ __('authorization.permission_settings_manage') }}</li>
                    @endcan
                    @can('customers.view')
                        <li class="flex items-center gap-2 text-[12px] text-slate-600 dark:text-slate-300"><x-ui.icon name="check-circle" class="size-4 text-brand-600 dark:text-brand-400" />{{ __('authorization.permission_customers_view') }}</li>
                    @endcan
                </ul>
            </div>
        </details>
    </x-app.page>
@endsection
