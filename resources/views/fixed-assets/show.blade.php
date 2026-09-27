@extends('layouts.app')

@section('content')
<x-app.page icon="building-office" :title="$asset->asset_number.' — '.$asset->name" :subtitle="__('fixed_assets.subtitle')">
    <x-slot:actions>
        <x-ui.button href="{{ route('assets.index') }}" variant="secondary">{{ __('fixed_assets.asset_register') }}</x-ui.button>
    </x-slot:actions>

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif

    <div class="grid gap-5 xl:grid-cols-3">
        <x-ui.card class="xl:col-span-2">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach([
                    [__('fixed_assets.category'), $asset->category?->name],
                    [__('fixed_assets.serial_number'), $asset->serial_number ?: '—'],
                    [__('fixed_assets.location'), $asset->location ?: '—'],
                    [__('fixed_assets.acquisition_date'), $asset->acquisition_date?->format('Y-m-d')],
                    [__('fixed_assets.in_service_date'), $asset->in_service_date?->format('Y-m-d')],
                    [__('fixed_assets.status'), __('fixed_assets.'.$asset->status)],
                    [__('fixed_assets.acquisition_cost'), number_format((float) $asset->acquisition_cost, 2)],
                    [__('fixed_assets.summary.accumulated_depreciation'), number_format((float) $asset->accumulated_depreciation, 2)],
                    [__('fixed_assets.summary.book_value'), number_format((float) $asset->book_value, 2)],
                    [__('fixed_assets.salvage_value'), number_format((float) $asset->salvage_value, 2)],
                    [__('fixed_assets.useful_life_months'), $asset->useful_life_months],
                    [__('fixed_assets.method'), __('fixed_assets.'.$asset->depreciation_method)],
                ] as [$label, $value])
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                        <p class="mt-1 font-medium text-slate-900 dark:text-white">{{ $value }}</p>
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        @can('assets.manage')
            <div class="space-y-5">
                @if($asset->status === 'active')
                    <x-ui.card>
                        <h2 class="mb-4 font-semibold text-slate-900 dark:text-white">{{ __('fixed_assets.depreciate_asset') }}</h2>
                        <form method="POST" action="{{ route('assets.depreciate', $asset) }}" class="space-y-4">
                            @csrf
                            <x-ui.input name="through_date" type="date" :value="now()->endOfMonth()->toDateString()" :label="__('fixed_assets.through_date')" required />
                            <div class="flex justify-end"><x-ui.button type="submit" icon="calculator">{{ __('fixed_assets.depreciate_asset') }}</x-ui.button></div>
                        </form>
                    </x-ui.card>

                    <x-ui.card>
                        <h2 class="mb-4 font-semibold text-slate-900 dark:text-white">{{ __('fixed_assets.dispose') }}</h2>
                        <form method="POST" action="{{ route('assets.dispose', $asset) }}" class="space-y-4" onsubmit="return confirm('{{ __('fixed_assets.confirm_dispose') }}')">
                            @csrf
                            <x-ui.input name="disposal_date" type="date" :value="now()->toDateString()" :label="__('fixed_assets.disposal_date')" required />
                            <x-ui.input name="proceeds" type="number" min="0" step="0.0001" value="0" :label="__('fixed_assets.proceeds')" required />
                            <x-ui.input name="note" :label="__('fixed_assets.disposal_note')" />
                            <div class="flex justify-end"><x-ui.button type="submit" variant="danger">{{ __('fixed_assets.dispose') }}</x-ui.button></div>
                        </form>
                    </x-ui.card>
                @endif
            </div>
        @endcan
    </div>

    <div class="mt-5">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('fixed_assets.history') }}</h2></x-slot:header>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('fixed_assets.period') }}</x-ui.th>
                            <x-ui.th>{{ __('fixed_assets.amount') }}</x-ui.th>
                            <x-ui.th>{{ __('fixed_assets.accumulated_after') }}</x-ui.th>
                            <x-ui.th>{{ __('fixed_assets.book_value_after') }}</x-ui.th>
                            <x-ui.th>{{ __('fixed_assets.journal') }}</x-ui.th>
                        </tr>
                    </x-slot:head>
                    @forelse($asset->depreciationEntries as $entry)
                        <tr>
                            <x-ui.td>{{ $entry->period_start?->format('Y-m-d') }} — {{ $entry->period_end?->format('Y-m-d') }}</x-ui.td>
                            <x-ui.td>{{ number_format((float) $entry->amount, 2) }}</x-ui.td>
                            <x-ui.td>{{ number_format((float) $entry->accumulated_after, 2) }}</x-ui.td>
                            <x-ui.td>{{ number_format((float) $entry->book_value_after, 2) }}</x-ui.td>
                            <x-ui.td>{{ $entry->journalEntry?->number }}</x-ui.td>
                        </tr>
                    @empty
                        <tr><x-ui.td colspan="5">—</x-ui.td></tr>
                    @endforelse
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>
</x-app.page>
@endsection
