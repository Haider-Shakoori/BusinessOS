@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('taxes.title')"
        :subtitle="__('taxes.subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('taxes.title')],
        ]"
    >
        <x-slot:actions>
            @can('taxes.manage')
                <x-ui.button href="{{ route('taxes.create') }}" icon="plus">{{ __('taxes.add') }}</x-ui.button>
            @endcan
        </x-slot:actions>

        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        @cannot('taxes.manage')
            <div class="mb-6">
                <x-ui.alert type="info">{{ __('taxes.view_only') }}</x-ui.alert>
            </div>
        @endcannot

        <form method="GET" action="{{ route('taxes.index') }}" class="mb-6" role="search">
            <label for="tax-search" class="sr-only">{{ __('taxes.search') }}</label>
            <div class="relative max-w-md">
                <x-ui.icon
                    name="search"
                    class="pointer-events-none absolute inset-y-0 start-0 my-auto ms-3 size-4 text-gray-400 dark:text-gray-500"
                    aria-hidden="true"
                />
                <input
                    id="tax-search"
                    type="search"
                    name="search"
                    value="{{ $searchTerm }}"
                    placeholder="{{ __('taxes.search_placeholder') }}"
                    class="block w-full rounded-lg border border-gray-300 bg-white py-2 pe-16 ps-10 text-sm text-gray-900 shadow-sm
                        placeholder:text-gray-400 transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30
                        dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-brand-400"
                />
                @if ($searchTerm !== '')
                    <a
                        href="{{ route('taxes.index') }}"
                        class="absolute inset-y-0 end-0 flex items-center pe-2.5 text-sm font-medium text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300"
                    >
                        {{ __('actions.clear') }}
                    </a>
                @endif
            </div>
            <input type="submit" value="{{ __('actions.search') }}" class="sr-only" />
        </form>

        @if ($taxes->isEmpty())
            <x-ui.card>
                <x-ui.empty-state
                    :title="$searchTerm !== '' ? __('taxes.no_results') : __('taxes.no_taxes')"
                    :description="$searchTerm !== '' ? __('taxes.no_results_description') : __('taxes.no_taxes_description')"
                    icon="percent"
                >
                    @can('taxes.manage')
                        <x-slot:actions>
                            <x-ui.button href="{{ route('taxes.create') }}" icon="plus">{{ __('taxes.add') }}</x-ui.button>
                        </x-slot:actions>
                    @endcan
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <x-ui.table :caption="__('taxes.table_caption')">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('taxes.columns.name') }}</x-ui.th>
                        <x-ui.th>{{ __('taxes.columns.rate') }}</x-ui.th>
                        <x-ui.th>{{ __('taxes.columns.created') }}</x-ui.th>
                        <x-ui.th class="text-end">
                            <span class="sr-only">{{ __('taxes.columns.actions') }}</span>
                        </x-ui.th>
                    </tr>
                </x-slot:head>

                @foreach ($taxes as $tax)
                    <tr>
                        <x-ui.td>
                            @can('taxes.manage')
                                <a
                                    href="{{ route('taxes.edit', $tax) }}"
                                    class="font-semibold text-gray-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                                >
                                    {{ $tax->name }}
                                </a>
                            @else
                                <span class="font-semibold text-gray-900 dark:text-white">{{ $tax->name }}</span>
                            @endcan
                        </x-ui.td>
                        <x-ui.td>
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $tax->rate }}%</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $tax->created_at?->format('Y-m-d') }}</span>
                        </x-ui.td>
                        <x-ui.td class="text-end">
                            <div class="inline-flex items-center gap-1">
                                @can('taxes.manage')
                                    <x-ui.icon-button
                                        variant="secondary"
                                        size="sm"
                                        icon="pencil-square"
                                        :label="__('actions.edit')"
                                        href="{{ route('taxes.edit', $tax) }}"
                                    />
                                    <x-ui.icon-button
                                        variant="danger"
                                        size="sm"
                                        icon="trash"
                                        :label="__('actions.delete')"
                                        x-on:click="$dispatch('bos:open-modal', { id: 'delete-tax-modal' }); $dispatch('bos:delete-tax', { id: {{ $tax->id }} })"
                                    />
                                @endcan
                            </div>
                        </x-ui.td>
                    </tr>
                @endforeach

                <x-slot:footer>
                    <div class="px-4 py-3">
                        <x-ui.pagination :paginator="$taxes" />
                    </div>
                </x-slot:footer>
            </x-ui.table>
        @endif
    </x-app.page>

    @can('taxes.manage')
        <x-ui.modal id="delete-tax-modal" :title="__('taxes.delete_confirm_title', ['name' => __('taxes.title')])" size="sm">
            <div x-data="deleteTaxDialog">
                <template x-if="taxId">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('taxes.delete_confirm') }}</p>

                        <form
                            class="mt-6 flex flex-wrap items-center justify-end gap-3"
                            method="POST"
                            x-bind:action="`/taxes/${taxId}`"
                        >
                            @csrf
                            @method('DELETE')
                            <button
                                type="button"
                                x-on:click="close"
                                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm transition-colors duration-150 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                            >
                                {{ __('taxes.delete_cancel') }}
                            </button>
                            <x-ui.button type="submit" variant="danger" icon="trash">{{ __('taxes.delete_submit') }}</x-ui.button>
                        </form>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    @endcan
@endsection