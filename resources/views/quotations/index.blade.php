@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('quotations.title')"
        :subtitle="__('quotations.subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('quotations.title')],
        ]"
    >
        <x-slot:actions>
            @can('quotations.manage')
                <x-ui.button href="{{ route('quotations.create') }}" icon="plus">{{ __('quotations.add') }}</x-ui.button>
            @endcan
        </x-slot:actions>

        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        @cannot('quotations.manage')
            <div class="mb-6">
                <x-ui.alert type="info">{{ __('quotations.view_only') }}</x-ui.alert>
            </div>
        @endcannot

        <div class="mb-6 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <form method="GET" action="{{ route('quotations.index') }}" class="lg:max-w-md lg:flex-1" role="search">
                <label for="quotation-search" class="sr-only">{{ __('quotations.search') }}</label>
                <div class="relative max-w-md">
                    <x-ui.icon
                        name="search"
                        class="pointer-events-none absolute inset-y-0 start-0 my-auto ms-3 size-4 text-gray-400 dark:text-gray-500"
                        aria-hidden="true"
                    />
                    <input
                        id="quotation-search"
                        type="search"
                        name="search"
                        value="{{ $searchTerm }}"
                        placeholder="{{ __('quotations.search_placeholder') }}"
                        class="block w-full rounded-lg border border-gray-300 bg-white py-2 pe-16 ps-10 text-sm text-gray-900 shadow-sm
                            placeholder:text-gray-400 transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30
                            dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-brand-400"
                    />
                    @if ($searchTerm !== '')
                        <a
                            href="{{ route('quotations.index') }}"
                            class="absolute inset-y-0 end-0 flex items-center pe-2.5 text-sm font-medium text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300"
                        >
                            {{ __('actions.clear') }}
                        </a>
                    @endif
                </div>
                <input type="submit" value="{{ __('actions.search') }}" class="sr-only" />
            </form>

            <div class="flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('quotations.status_filter') }}">
                <a
                    href="{{ route('quotations.index', $searchTerm !== '' ? ['search' => $searchTerm] : []) }}"
                    class="rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors duration-150 {{ $statusFilter === null ? 'bg-brand-600 text-white shadow-sm hover:bg-brand-700 dark:bg-brand-500 dark:hover:bg-brand-400' : 'border border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700' }}"
                >
                    {{ __('quotations.statuses.all') }}
                </a>
                @foreach ($statuses as $case)
                    <a
                        href="{{ route('quotations.index', array_filter([
                            'search' => $searchTerm !== '' ? $searchTerm : null,
                            'status' => $case->value,
                        ], fn ($v) => $v !== null)) }}"
                        class="rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors duration-150 {{ $statusFilter === $case->value ? 'bg-brand-600 text-white shadow-sm hover:bg-brand-700 dark:bg-brand-500 dark:hover:bg-brand-400' : 'border border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700' }}"
                    >
                        {{ __('quotations.statuses.'.$case->value) }}
                    </a>
                @endforeach
            </div>
        </div>

        @if ($quotations->isEmpty())
            <x-ui.card>
                <x-ui.empty-state
                    :title="$searchTerm !== '' || $statusFilter !== null ? __('quotations.no_results') : __('quotations.no_quotations')"
                    :description="$searchTerm !== '' || $statusFilter !== null ? __('quotations.no_results_description') : __('quotations.no_quotations_description')"
                    icon="document-text"
                >
                    @can('quotations.manage')
                        <x-slot:actions>
                            <x-ui.button href="{{ route('quotations.create') }}" icon="plus">{{ __('quotations.add') }}</x-ui.button>
                        </x-slot:actions>
                    @endcan
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <x-ui.table :caption="__('quotations.table_caption')">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('quotations.columns.number') }}</x-ui.th>
                        <x-ui.th>{{ __('quotations.columns.customer') }}</x-ui.th>
                        <x-ui.th>{{ __('quotations.columns.date') }}</x-ui.th>
                        <x-ui.th>{{ __('quotations.columns.expiry_date') }}</x-ui.th>
                        <x-ui.th>{{ __('quotations.columns.status') }}</x-ui.th>
                        <x-ui.th class="text-end">{{ __('quotations.columns.total') }}</x-ui.th>
                        <x-ui.th class="text-end">
                            <span class="sr-only">{{ __('quotations.columns.actions') }}</span>
                        </x-ui.th>
                    </tr>
                </x-slot:head>

                @foreach ($quotations as $quotation)
                    <tr>
                        <x-ui.td>
                            <a
                                href="{{ route('quotations.show', $quotation) }}"
                                class="whitespace-nowrap font-semibold text-gray-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                            >
                                {{ $quotation->quotation_number }}
                            </a>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-gray-500 dark:text-gray-400">{{ $quotation->customer?->name ?: __('quotations.no_customer') }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $quotation->date->format('Y-m-d') }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $quotation->expiry_date?->format('Y-m-d') ?: '—' }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <x-ui.status-badge :status="$quotation->status->value" :label="__('quotations.statuses.'.$quotation->status->value)" />
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap font-medium tabular-nums text-gray-900 dark:text-white">
                                {{ $quotation->total }} <span class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $quotation->currency_code }}</span>
                            </span>
                        </x-ui.td>
                        <x-ui.td class="text-end">
                            <div class="inline-flex items-center gap-1">
                                <x-ui.icon-button
                                    variant="secondary"
                                    size="sm"
                                    icon="eye"
                                    :label="__('actions.view')"
                                    href="{{ route('quotations.show', $quotation) }}"
                                />
                                @can('quotations.manage')
                                    @if ($quotation->status === \App\Enums\QuotationStatus::Draft)
                                        <x-ui.icon-button
                                            variant="secondary"
                                            size="sm"
                                            icon="pencil-square"
                                            :label="__('actions.edit')"
                                            href="{{ route('quotations.edit', $quotation) }}"
                                        />
                                        <x-ui.icon-button
                                            variant="danger"
                                            size="sm"
                                            icon="trash"
                                            :label="__('actions.delete')"
                                            x-on:click="$dispatch('bos:open-modal', { id: 'delete-quotation-modal' }); $dispatch('bos:delete-quotation', { id: {{ $quotation->id }} })"
                                        />
                                    @endif
                                @endcan
                            </div>
                        </x-ui.td>
                    </tr>
                @endforeach

                <x-slot:footer>
                    <div class="px-4 py-3">
                        <x-ui.pagination :paginator="$quotations" />
                    </div>
                </x-slot:footer>
            </x-ui.table>
        @endif
    </x-app.page>

    @can('quotations.manage')
        <x-ui.modal id="delete-quotation-modal" :title="__('quotations.delete_confirm_title', ['name' => __('quotations.document')])" size="sm">
            <div x-data="deleteQuotationDialog">
                <template x-if="quotationId">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('quotations.delete_confirm', ['name' => '']) }}</p>

                        <form
                            class="mt-6 flex flex-wrap items-center justify-end gap-3"
                            method="POST"
                            x-bind:action="`/quotations/${quotationId}`"
                        >
                            @csrf
                            @method('DELETE')
                            <button
                                type="button"
                                x-on:click="close"
                                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm transition-colors duration-150 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                            >
                                {{ __('quotations.delete_cancel') }}
                            </button>
                            <x-ui.button type="submit" variant="danger" icon="trash">{{ __('quotations.delete_submit') }}</x-ui.button>
                        </form>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    @endcan
@endsection