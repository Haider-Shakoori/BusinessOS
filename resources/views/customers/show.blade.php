@extends('layouts.app')

@section('content')
    @php
        $outstandingPositive = \App\Support\Decimal::gt($summary['outstanding_balance'] ?? '0', '0');
    @endphp

    <x-app.page icon="users"
        :title="$customer->name"
        :subtitle="__('customers.details_title')"
        :breadcrumbs="[
            ['label' => __('customers.title'), 'url' => route('customers.index')],
            ['label' => $customer->name],
        ]"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('customers.index') }}" icon="arrow-uturn-left">
                {{ __('customers.view_all') }}
            </x-ui.button>
            <x-ui.button variant="secondary" href="{{ route('customers.statement', $customer) }}" icon="document-text">
                {{ __('customers.statement.title') }}
            </x-ui.button>
            <x-ui.button variant="secondary" href="{{ route('customers.ledger', $customer) }}" icon="chart-bar">
                {{ __('customers.ledger.title') }}
            </x-ui.button>
            @can('customers.manage')
                <x-ui.button href="{{ route('customers.edit', $customer) }}" icon="pencil-square">{{ __('actions.edit') }}</x-ui.button>
                <x-ui.button
                    variant="danger"
                    icon="trash"
                    x-on:click="$dispatch('bos:open-modal', { id: 'delete-customer-modal' })"
                >
                    {{ __('actions.delete') }}
                </x-ui.button>
            @endcan
        </x-slot:actions>

        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <x-ui.stat-card
                :title="__('customers.ledger.outstanding_balance')"
                :value="$summary['outstanding_balance'] ?? '0'"
                icon="banknotes"
                :tone="$outstandingPositive ? 'warning' : 'success'"
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

        <p class="mt-4 flex items-start gap-1.5 text-xs text-slate-500 dark:text-slate-400">
            <x-ui.icon name="info-circle" class="mt-px size-4 shrink-0" />
            {{ __('customers.ledger.base_currency_note', ['currency' => $baseCurrency]) }}
        </p>

        <div class="mt-6 grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('customers.about') }}</h2>
                    </x-slot:header>

                    <dl class="grid gap-x-5 gap-y-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('customers.name') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $customer->name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('customers.company_name') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $customer->company_name ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('customers.email') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">
                                @if ($customer->email)
                                    <a href="mailto:{{ $customer->email }}" class="text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300">{{ $customer->email }}</a>
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('customers.phone') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">
                                @if ($customer->phone)
                                    <a href="tel:{{ $customer->phone }}" class="text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300">{{ $customer->phone }}</a>
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('customers.address') }}</dt>
                            <dd class="mt-1 whitespace-pre-line text-sm text-slate-900 dark:text-slate-100">{{ $customer->address ?: '—' }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('customers.notes') }}</dt>
                            <dd class="mt-1 whitespace-pre-line text-sm text-slate-900 dark:text-slate-100">{{ $customer->notes ?: '—' }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            </div>

            <div class="lg:col-span-1">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('customers.information') }}</h2>
                    </x-slot:header>

                    <dl class="space-y-4">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('customers.created_at') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $customer->created_at?->format('Y-m-d H:i') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('customers.updated_at') }}</dt>
                            <dd class="mt-1 text-sm text-slate-900 dark:text-slate-100">{{ $customer->updated_at?->format('Y-m-d H:i') }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            </div>
        </div>
    </x-app.page>

    @can('customers.manage')
        <x-ui.modal id="delete-customer-modal" :title="__('customers.delete_confirm_title', ['name' => $customer->name])" size="sm">
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('customers.delete_confirm') }}</p>

            <form method="POST" action="{{ route('customers.destroy', $customer) }}" class="mt-6 flex flex-wrap items-center justify-end gap-3">
                @csrf
                @method('DELETE')
                <button
                    type="button"
                    x-on:click="close"
                    class="inline-flex shrink-0 items-center justify-center rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition-colors duration-150 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700"
                >
                    {{ __('customers.delete_cancel') }}
                </button>
                <x-ui.button type="submit" variant="danger" icon="trash">{{ __('customers.delete_submit') }}</x-ui.button>
            </form>
        </x-ui.modal>
    @endcan
@endsection