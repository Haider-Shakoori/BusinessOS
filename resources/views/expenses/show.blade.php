@extends('layouts.app')

@section('content')
    <x-app.page
        :title="$expense->expense_number"
        :subtitle="__('expenses.information')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('expenses.title'), 'url' => route('expenses.index')],
            ['label' => $expense->expense_number],
        ]"
    >
        <x-slot:actions>
            <x-ui.button variant="secondary" href="{{ route('expenses.index') }}" icon="chevron-right">
                {{ __('expenses.title') }}
            </x-ui.button>
            @can('expenses.manage')
                <x-ui.button href="{{ route('expenses.edit', $expense) }}" icon="pencil-square">{{ __('actions.edit') }}</x-ui.button>
            @endcan
        </x-slot:actions>

        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        @cannot('expenses.manage')
            <div class="mb-6">
                <x-ui.alert type="info">{{ __('expenses.view_only') }}</x-ui.alert>
            </div>
        @endcannot

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('expenses.information') }}</h2>
                    </x-slot:header>

                    <dl class="grid gap-x-6 gap-y-5 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('expenses.number') }}</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $expense->expense_number }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('expenses.date') }}</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $expense->expense_date->format('Y-m-d') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('expenses.category') }}</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $expense->category?->name ?: __('expenses.no_category') }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('expenses.amount') }}</dt>
                            <dd class="mt-1 text-sm font-semibold tabular-nums text-gray-900 dark:text-white" dir="ltr">
                                {{ $expense->amount }} <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $expense->currency_code }}</span>
                            </dd>
                        </div>
                        @if ($expense->currency_code !== $baseCurrency)
                            <div>
                                <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('currencies.base_amount', ['currency' => $baseCurrency]) }}</dt>
                                <dd class="mt-1 text-sm tabular-nums text-gray-500 dark:text-gray-400" dir="ltr">{{ $expense->base_amount }}</dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('expenses.method') }}</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                {{ $expense->payment_method ? __('expenses.methods.'.$expense->payment_method->value) : __('expenses.no_method') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('expenses.reference') }}</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                @if ($expense->reference)
                                    <span dir="ltr">{{ $expense->reference }}</span>
                                @else
                                    {{ __('expenses.no_reference') }}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('expenses.vendor') }}</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $expense->vendor ?: __('expenses.no_vendor') }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('expenses.notes') }}</dt>
                            <dd class="mt-1 whitespace-pre-line text-sm text-gray-900 dark:text-gray-100">{{ $expense->notes ?: '—' }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            </div>

            <div class="lg:col-span-1">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('expenses.receipt') }}</h2>
                    </x-slot:header>

                    @if ($expense->receipt_path)
                        <a
                            href="{{ route('expenses.receipt', $expense) }}"
                            class="group flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 transition-colors hover:border-brand-300 hover:bg-brand-50 dark:border-gray-700 dark:bg-gray-800/60 dark:hover:border-brand-500/50 dark:hover:bg-brand-500/10"
                        >
                            <x-ui.icon name="document-text" class="size-6 shrink-0 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-gray-900 dark:text-white" dir="ltr">{{ basename($expense->receipt_path) }}</span>
                                <span class="mt-0.5 block text-xs font-medium text-brand-600 dark:text-brand-400">{{ __('expenses.view_receipt') }}</span>
                            </span>
                            <x-ui.icon name="arrow-trending-up" class="ms-auto size-4 shrink-0 text-gray-400 transition-colors group-hover:text-brand-600 dark:group-hover:text-brand-400" aria-hidden="true" />
                        </a>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('expenses.receipt_none') }}</p>
                    @endif
                </x-ui.card>

                <x-ui.card class="mt-6">
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('expenses.recorded_by') }}</h2>
                    </x-slot:header>

                    <dl class="space-y-4">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('expenses.recorded_by') }}</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $expense->createdBy?->name ?: '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('expenses.recorded_at') }}</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $expense->created_at?->format('Y-m-d H:i') }}</dd>
                        </div>
                    </dl>
                </x-ui.card>
            </div>
        </div>
    </x-app.page>
@endsection