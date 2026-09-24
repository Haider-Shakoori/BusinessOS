@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('customers.title')"
        :subtitle="__('customers.subtitle')"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('customers.title')],
        ]"
    >
        <x-slot:actions>
            @can('customers.manage')
                <x-ui.button href="{{ route('customers.import') }}" variant="secondary" icon="document-text">{{ __('imports.import_customers') }}</x-ui.button>
                <x-ui.button href="{{ route('customers.create') }}" icon="plus">{{ __('customers.add') }}</x-ui.button>
            @endcan
        </x-slot:actions>

        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        @cannot('customers.manage')
            <div class="mb-6">
                <x-ui.alert type="info">{{ __('customers.view_only') }}</x-ui.alert>
            </div>
        @endcannot

        <form method="GET" action="{{ route('customers.index') }}" class="mb-6" role="search">
            <label for="customer-search" class="sr-only">{{ __('customers.search') }}</label>
            <div class="relative max-w-md">
                <x-ui.icon
                    name="search"
                    class="pointer-events-none absolute inset-y-0 start-0 my-auto ms-3 size-4 text-gray-400 dark:text-gray-500"
                    aria-hidden="true"
                />
                <input
                    id="customer-search"
                    type="search"
                    name="search"
                    value="{{ $searchTerm }}"
                    placeholder="{{ __('customers.search_placeholder') }}"
                    class="block w-full rounded-lg border border-gray-300 bg-white py-2 pe-16 ps-10 text-sm text-gray-900 shadow-sm
                        placeholder:text-gray-400 transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30
                        dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:border-brand-400"
                />
                @if ($searchTerm !== '')
                    <a
                        href="{{ route('customers.index') }}"
                        class="absolute inset-y-0 end-0 flex items-center pe-2.5 text-sm font-medium text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300"
                    >
                        {{ __('actions.clear') }}
                    </a>
                @endif
            </div>
            <input type="submit" value="{{ __('actions.search') }}" class="sr-only" />
        </form>

        @if ($customers->isEmpty())
            <x-ui.card>
                <x-ui.empty-state
                    :title="$searchTerm !== '' ? __('customers.no_results') : __('customers.no_customers')"
                    :description="$searchTerm !== '' ? __('customers.no_results_description') : __('customers.no_customers_description')"
                    icon="users"
                >
                    @can('customers.manage')
                        <x-slot:actions>
                            <x-ui.button href="{{ route('customers.create') }}" icon="plus">{{ __('customers.add') }}</x-ui.button>
                        </x-slot:actions>
                    @endcan
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <x-ui.table :caption="__('customers.table_caption')">
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('customers.name') }}</x-ui.th>
                        <x-ui.th>{{ __('customers.company_name') }}</x-ui.th>
                        <x-ui.th>{{ __('customers.columns.contact') }}</x-ui.th>
                        <x-ui.th>{{ __('customers.columns.notes') }}</x-ui.th>
                        <x-ui.th>{{ __('customers.columns.created') }}</x-ui.th>
                        <x-ui.th class="text-end">
                            <span class="sr-only">{{ __('customers.columns.actions') }}</span>
                        </x-ui.th>
                    </tr>
                </x-slot:head>

                @foreach ($customers as $customer)
                    <tr>
                        <x-ui.td>
                            <a
                                href="{{ route('customers.show', $customer) }}"
                                class="font-semibold text-gray-900 transition-colors hover:text-brand-600 dark:text-white dark:hover:text-brand-400"
                            >
                                {{ $customer->name }}
                            </a>
                        </x-ui.td>
                        <x-ui.td>
                            <span>{{ $customer->company_name ?: '—' }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <div class="flex flex-col">
                                @if ($customer->email)
                                    <span class="text-gray-900 dark:text-gray-100">{{ $customer->email }}</span>
                                @endif
                                @if ($customer->phone)
                                    <span class="text-gray-500 dark:text-gray-400">{{ $customer->phone }}</span>
                                @endif
                            </div>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="text-gray-500 dark:text-gray-400">{{ Str::limit($customer->notes, 40) }}</span>
                        </x-ui.td>
                        <x-ui.td>
                            <span class="whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $customer->created_at?->format('Y-m-d') }}</span>
                        </x-ui.td>
                        <x-ui.td class="text-end">
                            <div class="inline-flex items-center gap-1">
                                <x-ui.icon-button
                                    variant="secondary"
                                    size="sm"
                                    icon="eye"
                                    :label="__('actions.view')"
                                    href="{{ route('customers.show', $customer) }}"
                                />
                                @can('customers.manage')
                                    <x-ui.icon-button
                                        variant="secondary"
                                        size="sm"
                                        icon="pencil-square"
                                        :label="__('actions.edit')"
                                        href="{{ route('customers.edit', $customer) }}"
                                    />
                                    <x-ui.icon-button
                                        variant="danger"
                                        size="sm"
                                        icon="trash"
                                        :label="__('actions.delete')"
                                        x-on:click="$dispatch('bos:open-modal', { id: 'delete-customer-modal' }); $dispatch('bos:delete-customer', { id: {{ $customer->id }} })"
                                    />
                                @endcan
                            </div>
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
    </x-app.page>

    @can('customers.manage')
        <x-ui.modal id="delete-customer-modal" :title="__('customers.delete_confirm_title', ['name' => __('customers.title')])" size="sm">
            <div x-data="deleteCustomerDialog">
                <template x-if="customerId">
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('customers.delete_confirm') }}</p>

                        <form
                            class="mt-6 flex flex-wrap items-center justify-end gap-3"
                            method="POST"
                            x-bind:action="`/customers/${customerId}`"
                        >
                            @csrf
                            @method('DELETE')
                            <button
                                type="button"
                                x-on:click="close"
                                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-gray-300 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 shadow-sm transition-colors duration-150 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
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