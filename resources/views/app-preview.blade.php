@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('common.application_shell_preview')"
        :subtitle="__('common.shell_preview_description')"
        :breadcrumbs="[['label' => __('common.home'), 'url' => route('app.preview')], ['label' => __('common.application_shell_preview')]]"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" icon="download">{{ __('common.export') }}</x-ui.button>
            <x-ui.button icon="plus">{{ __('common.new_invoice') }}</x-ui.button>
        </x-slot:actions>

        {{-- Stat cards row --}}
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card :title="__('common.total_revenue')" value="$48,210.00" :trend="'+12.4% ' . __('common.this_month')" icon="chart-bar" tone="brand" />
            <x-ui.stat-card :title="__('common.open_invoices')" value="18" :trend="'-4.1% ' . __('common.vs_last_month')" :trend-up="false" icon="document-text" tone="danger" />
            <x-ui.stat-card :title="__('common.active_customers')" value="1,204" :trend="'+8.2% ' . __('common.this_quarter')" icon="users" tone="success" />
            <x-ui.stat-card :title="__('common.pending_expenses')" value="$6,980.50" :hint="__('common.awaiting_settlement')" icon="arrow-trending-down" tone="warning" />
        </div>

        {{-- Two-column content area --}}
        <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-ui.card class="lg:col-span-2">
                <x-slot:header>
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('common.recent_activity') }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('common.recent_activity_description') }}</p>
                    </div>
                    <x-slot:actions>
                        <x-ui.status-badge status="active" />
                    </x-slot:actions>
                </x-slot:header>

                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('common.type') }}</x-ui.th>
                            <x-ui.th>{{ __('common.reference') }}</x-ui.th>
                            <x-ui.th>{{ __('common.amount') }}</x-ui.th>
                            <x-ui.th>{{ __('common.status') }}</x-ui.th>
                            <x-ui.th>{{ __('common.date') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    <tr>
                        <x-ui.td class="font-medium text-gray-900 dark:text-gray-100">{{ __('common.invoice') }}</x-ui.td>
                        <x-ui.td>INV-2026-0001</x-ui.td>
                        <x-ui.td numeric>$1,240.00</x-ui.td>
                        <x-ui.td><x-ui.status-badge status="paid" /></x-ui.td>
                        <x-ui.td>Jan 15, 2026</x-ui.td>
                    </tr>
                    <tr>
                        <x-ui.td class="font-medium text-gray-900 dark:text-gray-100">{{ __('common.payment') }}</x-ui.td>
                        <x-ui.td>PAY-2026-0003</x-ui.td>
                        <x-ui.td numeric>$860.00</x-ui.td>
                        <x-ui.td><x-ui.status-badge status="pending" /></x-ui.td>
                        <x-ui.td>Jan 14, 2026</x-ui.td>
                    </tr>
                    <tr>
                        <x-ui.td class="font-medium text-gray-900 dark:text-gray-100">{{ __('common.expense') }}</x-ui.td>
                        <x-ui.td>EXP-2026-0007</x-ui.td>
                        <x-ui.td numeric>$45.00</x-ui.td>
                        <x-ui.td><x-ui.status-badge status="draft" /></x-ui.td>
                        <x-ui.td>Jan 13, 2026</x-ui.td>
                    </tr>
                    <tr>
                        <x-ui.td class="font-medium text-gray-900 dark:text-gray-100">{{ __('common.quotation') }}</x-ui.td>
                        <x-ui.td>QUO-2026-0002</x-ui.td>
                        <x-ui.td numeric>$2,130.00</x-ui.td>
                        <x-ui.td><x-ui.status-badge status="sent" /></x-ui.td>
                        <x-ui.td>Jan 12, 2026</x-ui.td>
                    </tr>
                </x-ui.table>
            </x-ui.card>

            <x-ui.card>
                <x-slot:header>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('common.quick_actions_title') }}</h3>
                </x-slot:header>
                <div class="space-y-3">
                    <x-ui.button class="w-full justify-start" icon="plus">{{ __('common.create_invoice') }}</x-ui.button>
                    <x-ui.button class="w-full justify-start" variant="secondary" icon="users">{{ __('common.add_customer') }}</x-ui.button>
                    <x-ui.button class="w-full justify-start" variant="secondary" icon="banknotes">{{ __('common.add_product') }}</x-ui.button>
                    <x-ui.button class="w-full justify-start" variant="secondary" icon="arrow-trending-down">{{ __('common.record_expense') }}</x-ui.button>
                </div>
            </x-ui.card>
        </div>

        {{-- Alerts demo + empty state --}}
        <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
            <x-ui.card>
                <x-slot:header>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('common.system_alerts') }}</h3>
                </x-slot:header>
                <div class="space-y-3">
                    <x-ui.alert type="info" :title="__('common.welcome_title')" dismissible>{{ __('common.welcome_description') }}</x-ui.alert>
                    <x-ui.alert type="success" dismissible>{{ __('common.theme_persisted') }}</x-ui.alert>
                    <x-ui.alert type="warning" dismissible>{{ __('common.mobile_drawer_pattern') }}</x-ui.alert>
                </div>
            </x-ui.card>

            <x-ui.card>
                <x-slot:header>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ __('common.no_data_state') }}</h3>
                </x-slot:header>
                <x-ui.empty-state
                    :title="__('common.no_recent_notifications')"
                    :description="__('common.no_recent_notifications_description')"
                    icon="bell"
                >
                    <x-slot:actions>
                        <x-ui.button size="sm" variant="secondary">{{ __('common.clear_all') }}</x-ui.button>
                    </x-slot:actions>
                </x-ui.empty-state>
            </x-ui.card>
        </div>
    </x-app.page>
@endsection