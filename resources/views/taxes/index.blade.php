@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('taxes.title')"
        :subtitle="__('taxes.subtitle')"
        icon="percent"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('taxes.title')],
        ]"
    >
        @if (session('status'))
            <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
        @endif

        @cannot('taxes.manage')
            <div class="mb-5"><x-ui.alert type="info">{{ __('taxes.view_only') }}</x-ui.alert></div>
        @endcannot

        <x-ui.list-panel>
            <x-slot:actions>
                @can('taxes.manage')
                    <x-ui.button href="{{ route('taxes.create') }}" variant="outline" icon="plus">{{ __('taxes.add') }}</x-ui.button>
                @endcan
            </x-slot:actions>

            <x-slot:filters>
                <form method="GET" action="{{ route('taxes.index') }}" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto]" role="search">
                    <x-ui.search-input name="search" :label="__('taxes.search')" :placeholder="__('taxes.search_placeholder')" :value="$searchTerm" />
                    <div class="flex items-end gap-2">
                        <x-ui.button type="submit" icon="search">{{ __('actions.search') }}</x-ui.button>
                        @if ($searchTerm !== '')<x-ui.button variant="secondary" href="{{ route('taxes.index') }}">{{ __('actions.clear') }}</x-ui.button>@endif
                    </div>
                </form>
            </x-slot:filters>

            @if ($taxes->isEmpty())
                <x-ui.empty-state
                    :title="$searchTerm !== '' ? __('taxes.no_results') : __('taxes.no_taxes')"
                    :description="$searchTerm !== '' ? __('taxes.no_results_description') : __('taxes.no_taxes_description')"
                    icon="percent"
                >
                    @can('taxes.manage')
                        <x-slot:actions><x-ui.button href="{{ route('taxes.create') }}" icon="plus">{{ __('taxes.add') }}</x-ui.button></x-slot:actions>
                    @endcan
                </x-ui.empty-state>
            @else
                <x-ui.table :caption="__('taxes.table_caption')" class="shadow-none">
                    <x-slot:head>
                        <tr>
                            <x-ui.th class="w-12 text-center">#</x-ui.th>
                            <x-ui.th>{{ __('taxes.columns.name') }}</x-ui.th>
                            <x-ui.th numeric>{{ __('taxes.columns.rate') }}</x-ui.th>
                            <x-ui.th>{{ __('taxes.columns.created') }}</x-ui.th>
                            <x-ui.th class="w-16 text-center"><span class="sr-only">{{ __('taxes.columns.actions') }}</span></x-ui.th>
                        </tr>
                    </x-slot:head>

                    @foreach ($taxes as $tax)
                        <tr>
                            <x-ui.td class="text-center text-slate-500">{{ ($taxes->firstItem() ?? 1) + $loop->index }}</x-ui.td>
                            <x-ui.td>
                                @can('taxes.manage')
                                    <a href="{{ route('taxes.edit', $tax) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">{{ $tax->name }}</a>
                                @else
                                    <span class="font-semibold text-slate-900 dark:text-white">{{ $tax->name }}</span>
                                @endcan
                            </x-ui.td>
                            <x-ui.td numeric><span class="whitespace-nowrap font-semibold">{{ number_format((float) $tax->rate, 4) }}%</span></x-ui.td>
                            <x-ui.td><span class="whitespace-nowrap text-slate-500 dark:text-slate-400">{{ $tax->created_at?->format('Y-m-d') }}</span></x-ui.td>
                            <x-ui.td class="text-center">
                                @can('taxes.manage')
                                    <x-ui.dropdown align="end" width="w-40" :chevron="false" label="{{ __('taxes.columns.actions') }}">
                                        <x-slot:trigger><span class="grid size-8 place-items-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"><x-ui.icon name="ellipsis-horizontal" class="size-5" /></span></x-slot:trigger>
                                        <x-slot:items>
                                            <x-ui.dropdown-item :href="route('taxes.edit', $tax)" icon="pencil-square">{{ __('actions.edit') }}</x-ui.dropdown-item>
                                            <button type="button" role="menuitem" class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10" x-on:click="close(); $dispatch('bos:open-modal', { id: 'delete-tax-modal' }); $dispatch('bos:delete-tax', { id: {{ $tax->id }} })"><x-ui.icon name="trash" class="size-4" />{{ __('actions.delete') }}</button>
                                        </x-slot:items>
                                    </x-ui.dropdown>
                                @endcan
                            </x-ui.td>
                        </tr>
                    @endforeach

                    <x-slot:footer><div class="px-4 py-3"><x-ui.pagination :paginator="$taxes" /></div></x-slot:footer>
                </x-ui.table>
            @endif
        </x-ui.list-panel>
    </x-app.page>

    @can('taxes.manage')
        <x-ui.modal id="delete-tax-modal" :title="__('taxes.delete_confirm_title', ['name' => __('taxes.title')])" size="sm">
            <div x-data="deleteTaxDialog">
                <template x-if="taxId">
                    <div>
                        <p class="text-[12px] leading-5 text-slate-500 dark:text-slate-400">{{ __('taxes.delete_confirm') }}</p>
                        <form class="mt-6 flex flex-wrap items-center justify-end gap-2" method="POST" x-bind:action="`/taxes/${taxId}`">
                            @csrf
                            @method('DELETE')
                            <x-ui.button type="button" variant="secondary" x-on:click="close">{{ __('taxes.delete_cancel') }}</x-ui.button>
                            <x-ui.button type="submit" variant="danger" icon="trash">{{ __('taxes.delete_submit') }}</x-ui.button>
                        </form>
                    </div>
                </template>
            </div>
        </x-ui.modal>
    @endcan
@endsection
