@extends('layouts.app')

@section('content')
<x-app.page
    icon="users"
    :title="__('suppliers.title')"
    :subtitle="__('suppliers.subtitle')"
    :breadcrumbs="[
        ['label' => __('modules.dashboard'), 'url' => route('app.home')],
        ['label' => __('suppliers.title')],
    ]"
>
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button variant="secondary" href="{{ route('purchasing.index') }}" icon="shopping-cart">{{ __('suppliers.view_purchasing') }}</x-ui.button>
            @can('suppliers.manage')
                <x-ui.button variant="secondary" href="{{ route('suppliers.import') }}" icon="arrow-up-tray">{{ __('suppliers.import_csv') }}</x-ui.button>
                <x-ui.button href="{{ route('suppliers.create') }}" icon="plus">{{ __('suppliers.create') }}</x-ui.button>
            @endcan
        </div>
    </x-slot:actions>

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif

    <x-ui.list-panel>
        <x-slot:filters>
            <form method="GET" action="{{ route('suppliers.index') }}" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto]" role="search">
                <x-ui.search-input name="search" :label="__('suppliers.search')" :placeholder="__('suppliers.search_placeholder')" :value="$searchTerm" />
                <div class="flex items-end gap-2">
                    <x-ui.button type="submit" icon="search">{{ __('actions.search') }}</x-ui.button>
                    @if($searchTerm !== '')
                        <x-ui.button href="{{ route('suppliers.index') }}" variant="secondary">{{ __('actions.clear') }}</x-ui.button>
                    @endif
                </div>
            </form>
        </x-slot:filters>

        @if($suppliers->isEmpty())
            <x-ui.empty-state
                icon="users"
                :title="$searchTerm !== '' ? __('suppliers.no_results') : __('suppliers.no_suppliers')"
                :description="__('suppliers.subtitle')"
            />
        @else
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <x-ui.th class="w-12 text-center">#</x-ui.th>
                        <x-ui.th>{{ __('suppliers.code') }}</x-ui.th>
                        <x-ui.th>{{ __('suppliers.name') }}</x-ui.th>
                        <x-ui.th>{{ __('suppliers.contact') }}</x-ui.th>
                        <x-ui.th numeric>{{ __('suppliers.outstanding') }}</x-ui.th>
                        <x-ui.th class="w-20"></x-ui.th>
                    </tr>
                </x-slot:head>

                @foreach($suppliers as $supplier)
                    <tr>
                        <x-ui.td class="text-center text-slate-500">{{ ($suppliers->firstItem() ?? 1) + $loop->index }}</x-ui.td>
                        <x-ui.td><span class="font-medium" dir="ltr">{{ $supplier->code }}</span></x-ui.td>
                        <x-ui.td>
                            <a href="{{ route('suppliers.show', $supplier) }}" class="font-semibold text-slate-900 hover:text-brand-600 dark:text-white dark:hover:text-brand-400">{{ $supplier->name }}</a>
                        </x-ui.td>
                        <x-ui.td>
                            <div class="text-sm text-slate-600 dark:text-slate-300">
                                @if($supplier->phone)<div dir="ltr">{{ $supplier->phone }}</div>@endif
                                @if($supplier->email)<div dir="ltr">{{ $supplier->email }}</div>@endif
                            </div>
                        </x-ui.td>
                        <x-ui.td numeric><span class="font-semibold tabular-nums">{{ $baseCurrency }} {{ number_format((float) $supplier->outstanding_balance, 4) }}</span></x-ui.td>
                        <x-ui.td>
                            <x-ui.button href="{{ route('suppliers.show', $supplier) }}" size="sm" variant="secondary" icon="eye">{{ __('actions.view') }}</x-ui.button>
                        </x-ui.td>
                    </tr>
                @endforeach

                <x-slot:footer><div class="px-4 py-3"><x-ui.pagination :paginator="$suppliers" /></div></x-slot:footer>
            </x-ui.table>
        @endif
    </x-ui.list-panel>
</x-app.page>
@endsection
