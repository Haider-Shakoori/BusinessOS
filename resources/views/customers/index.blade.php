@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('customers.title')"
        :subtitle="__('customers.subtitle')"
        icon="users"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('customers.title')],
        ]"
    >
        @if (session('status'))
            <div class="mb-5">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        @cannot('customers.manage')
            <div class="mb-5">
                <x-ui.alert type="info">{{ __('customers.view_only') }}</x-ui.alert>
            </div>
        @endcannot

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat-card
                :title="__('customers.stats.total_customers')"
                :value="$totalCustomers"
                :trend="__('customers.stats.new_this_month', ['count' => $newThisMonth])"
                icon="users"
                tone="brand"
            />
            <x-ui.stat-card
                :title="__('customers.stats.total_receivables')"
                :value="$baseCurrency.' '.number_format((float) $totalReceivables, 4)"
                icon="user"
                tone="success"
            />
            <x-ui.stat-card
                :title="__('customers.stats.outstanding_invoices')"
                :value="$baseCurrency.' '.number_format((float) $outstandingInvoices, 4)"
                icon="receipt-percent"
                tone="danger"
                value-class="text-red-600 dark:text-red-400"
            />
            <x-ui.stat-card
                :title="__('customers.stats.active_customers')"
                :value="$totalCustomers"
                :hint="__('customers.stats.all_current')"
                icon="check-circle"
                tone="neutral"
            />
        </div>

        <x-ui.card bare class="mt-5">
            <div class="p-5">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                    <h2 class="flex items-center gap-2.5 text-[15px] font-semibold text-slate-900 dark:text-white">
                        <x-ui.icon name="funnel" class="size-5 text-brand-600 dark:text-brand-400" />
                        {{ __('customers.filters') }}
                    </h2>

                    <div class="flex flex-wrap items-center gap-2">
                        @can('customers.manage')
                            <x-ui.button href="{{ route('customers.create') }}" variant="outline" icon="plus">
                                {{ __('customers.add') }}
                            </x-ui.button>
                            <x-ui.button href="{{ route('customers.import') }}" variant="outline" icon="arrow-up-tray">
                                {{ __('imports.import_customers') }}
                            </x-ui.button>
                        @endcan

                        <x-ui.dropdown
                            align="end"
                            width="w-72"
                            :chevron="false"
                            label="{{ __('exports.export_csv') }}"
                            class="rounded-[7px]"
                        >
                            <x-slot:trigger>
                                <span class="inline-flex items-center gap-2 rounded-[7px] border border-brand-600 bg-brand-600 px-3.5 py-[8px] text-[13px] font-semibold text-white shadow-sm transition-colors hover:bg-brand-700">
                                    <x-ui.icon name="arrow-down-tray" class="size-4" />
                                    {{ __('exports.export_csv') }}
                                    <x-ui.icon name="chevron-down" class="size-3.5" />
                                </span>
                            </x-slot:trigger>
                            <x-slot:items>
                                <a
                                    href="{{ route('customers.export', request()->only('search', 'status', 'date_from', 'date_to')) }}"
                                    role="menuitem"
                                    class="flex items-start gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-slate-50 dark:hover:bg-slate-700"
                                >
                                    <x-ui.icon name="arrow-down-tray" class="mt-0.5 size-4 shrink-0 text-brand-600 dark:text-brand-400" />
                                    <span>
                                        <span class="block text-[13px] font-medium text-slate-800 dark:text-slate-100">{{ __('customers.export_current_list') }}</span>
                                        <span class="mt-0.5 block text-[11px] leading-4 text-slate-500 dark:text-slate-400">{{ __('customers.export_current_help') }}</span>
                                    </span>
                                </a>
                                @can('customers.manage')
                                    <a
                                        href="{{ route('customers.import.template') }}"
                                        role="menuitem"
                                        class="flex items-start gap-3 rounded-lg px-3 py-2.5 transition-colors hover:bg-slate-50 dark:hover:bg-slate-700"
                                    >
                                        <x-ui.icon name="arrow-down-tray" class="mt-0.5 size-4 shrink-0 text-brand-600 dark:text-brand-400" />
                                        <span>
                                            <span class="block text-[13px] font-medium text-slate-800 dark:text-slate-100">{{ __('customers.download_template') }}</span>
                                            <span class="mt-0.5 block text-[11px] leading-4 text-slate-500 dark:text-slate-400">{{ __('customers.download_template_help') }}</span>
                                        </span>
                                    </a>
                                @endcan
                            </x-slot:items>
                        </x-ui.dropdown>
                    </div>
                </div>

                <form method="GET" action="{{ route('customers.index') }}" class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-[1.25fr_1fr_1fr_1fr_auto]" role="search">
                    <x-ui.input
                        name="search"
                        :label="__('customers.search')"
                        :placeholder="__('customers.search_placeholder')"
                        :value="$searchTerm"
                    />

                    <x-ui.select
                        name="status"
                        :label="__('customers.status')"
                        :value="$statusFilter"
                    >
                        <option value="">{{ __('customers.all_statuses') }}</option>
                        <option value="active" @selected($statusFilter === 'active')>{{ __('customers.active') }}</option>
                    </x-ui.select>

                    <x-ui.input
                        name="date_from"
                        type="date"
                        :label="__('customers.date_from')"
                        :value="$dateFrom"
                    />

                    <x-ui.input
                        name="date_to"
                        type="date"
                        :label="__('customers.date_to')"
                        :value="$dateTo"
                    />

                    <div class="flex items-end gap-2">
                        <x-ui.button type="submit" icon="search">{{ __('customers.apply_filters') }}</x-ui.button>
                        @if ($searchTerm !== '' || $statusFilter !== null || $dateFrom !== null || $dateTo !== null)
                            <x-ui.button variant="secondary" href="{{ route('customers.index') }}">{{ __('actions.clear') }}</x-ui.button>
                        @endif
                    </div>
                </form>
            </div>

            <div class="border-t border-slate-200 px-5 pb-5 pt-4 dark:border-slate-700">
                @if ($customers->isEmpty())
                    <x-ui.empty-state
                        :title="$searchTerm !== '' || $statusFilter !== null || $dateFrom !== null || $dateTo !== null ? __('customers.no_results') : __('customers.no_customers')"
                        :description="$searchTerm !== '' || $statusFilter !== null || $dateFrom !== null || $dateTo !== null ? __('customers.no_results_description') : __('customers.no_customers_description')"
                        icon="users"
                    >
                        @can('customers.manage')
                            <x-slot:actions>
                                <x-ui.button href="{{ route('customers.create') }}" icon="plus">{{ __('customers.add') }}</x-ui.button>
                            </x-slot:actions>
                        @endcan
                    </x-ui.empty-state>
                @else
                    <x-ui.table :caption="__('customers.table_caption')" class="shadow-none">
                        <x-slot:head>
                            <tr>
                                <x-ui.th class="w-12 text-center">#</x-ui.th>
                                <x-ui.th>{{ __('customers.name') }}</x-ui.th>
                                <x-ui.th>{{ __('customers.email') }}</x-ui.th>
                                <x-ui.th>{{ __('customers.phone') }}</x-ui.th>
                                <x-ui.th>{{ __('customers.address') }}</x-ui.th>
                                <x-ui.th numeric>{{ __('customers.opening_balance') }}</x-ui.th>
                                <x-ui.th>{{ __('customers.opening_balance_date') }}</x-ui.th>
                                <x-ui.th>{{ __('customers.status') }}</x-ui.th>
                                <x-ui.th class="w-16 text-center">
                                    <span class="sr-only">{{ __('customers.columns.actions') }}</span>
                                </x-ui.th>
                            </tr>
                        </x-slot:head>

                        @foreach ($customers as $customer)
                            <tr class="transition-colors hover:bg-slate-50/70 dark:hover:bg-slate-800/40">
                                <x-ui.td class="text-center text-slate-500">
                                    {{ ($customers->firstItem() ?? 1) + $loop->index }}
                                </x-ui.td>
                                <x-ui.td>
                                    <a href="{{ route('customers.show', $customer) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">
                                        {{ $customer->name }}
                                    </a>
                                    @if ($customer->company_name)
                                        <span class="mt-0.5 block text-[11px] text-slate-500 dark:text-slate-400">{{ $customer->company_name }}</span>
                                    @endif
                                </x-ui.td>
                                <x-ui.td>{{ $customer->email ?: '—' }}</x-ui.td>
                                <x-ui.td><span dir="ltr">{{ $customer->phone ?: '—' }}</span></x-ui.td>
                                <x-ui.td>
                                    <span class="block max-w-[220px] truncate" title="{{ $customer->address }}">{{ $customer->address ?: '—' }}</span>
                                </x-ui.td>
                                <x-ui.td numeric>
                                    <span class="whitespace-nowrap">{{ $baseCurrency }} {{ number_format((float) $customer->opening_balance, 4) }}</span>
                                </x-ui.td>
                                <x-ui.td>
                                    <span class="whitespace-nowrap">{{ $customer->opening_balance_date?->format('Y-m-d') ?? '—' }}</span>
                                </x-ui.td>
                                <x-ui.td>
                                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-1 text-[11px] font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                                        {{ __('customers.active') }}
                                    </span>
                                </x-ui.td>
                                <x-ui.td class="text-center">
                                    <x-ui.dropdown align="end" width="w-44" :chevron="false" label="{{ __('customers.columns.actions') }}">
                                        <x-slot:trigger>
                                            <span class="grid size-8 place-items-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white">
                                                <x-ui.icon name="ellipsis-horizontal" class="size-5" />
                                            </span>
                                        </x-slot:trigger>
                                        <x-slot:items>
                                            <x-ui.dropdown-item :href="route('customers.show', $customer)" icon="eye">{{ __('actions.view') }}</x-ui.dropdown-item>
                                            @can('customers.manage')
                                                <x-ui.dropdown-item :href="route('customers.edit', $customer)" icon="pencil-square">{{ __('actions.edit') }}</x-ui.dropdown-item>
                                                <button
                                                    type="button"
                                                    role="menuitem"
                                                    class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-red-600 transition-colors hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
                                                    x-on:click="close(); $dispatch('bos:open-modal', { id: 'delete-customer-modal' }); $dispatch('bos:delete-customer', { id: {{ $customer->id }} })"
                                                >
                                                    <x-ui.icon name="trash" class="size-4" />
                                                    {{ __('actions.delete') }}
                                                </button>
                                            @endcan
                                        </x-slot:items>
                                    </x-ui.dropdown>
                                </x-ui.td>
                            </tr>
                        @endforeach

                        <x-slot:footer>
                            <div class="px-4 py-3">
                                <x-ui.pagination :paginator="$customers" />
                            </div>
                        </x-slot:footer>
                    </x-ui.table>
                @endif
            </div>
        </x-ui.card>
    </x-app.page>

    @can('customers.manage')
        <x-ui.modal id="delete-customer-modal" :title="__('customers.delete_confirm_title', ['name' => __('customers.title')])" size="sm">
            <div x-data="deleteCustomerDialog">
                <template x-if="customerId">
                    <div>
                        <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('customers.delete_confirm') }}</p>

                        <form class="mt-6 flex flex-wrap items-center justify-end gap-3" method="POST" x-bind:action="`/customers/${customerId}`">
                            @csrf
                            @method('DELETE')
                            <button
                                type="button"
                                x-on:click="close"
                                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition-colors hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
                            >
                                {{ __('customers.delete_cancel') }}
                            </button>
                            <x-ui.button type="submit" variant="danger" icon="trash">{{ __('customers.delete_submit') }}</x-ui.button>
                        </form>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    @endcan
@endsection
