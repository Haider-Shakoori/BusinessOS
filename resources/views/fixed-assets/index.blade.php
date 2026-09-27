@extends('layouts.app')

@section('content')
<x-app.page icon="building-office" :title="__('fixed_assets.title')" :subtitle="__('fixed_assets.subtitle')">
    <x-slot:actions>
        <x-ui.button href="{{ route('accounting.index') }}" variant="secondary" icon="ledger">
            {{ __('operations.accounting.title') }}
        </x-ui.button>
    </x-slot:actions>

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        @foreach([
            [__('fixed_assets.summary.active_assets'), $summary['count']],
            [__('fixed_assets.summary.acquisition_cost'), number_format((float) $summary['cost'], 2)],
            [__('fixed_assets.summary.accumulated_depreciation'), number_format((float) $summary['accumulated'], 2)],
            [__('fixed_assets.summary.book_value'), number_format((float) $summary['book_value'], 2)],
        ] as [$label, $value])
            <x-ui.card>
                <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>
                <p class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{{ $value }}</p>
            </x-ui.card>
        @endforeach
    </div>

    @can('assets.manage')
        <div class="mt-5 grid gap-5 xl:grid-cols-2">
            <x-ui.card>
                <x-slot:header>
                    <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('fixed_assets.new_category') }}</h2>
                </x-slot:header>

                <form method="POST" action="{{ route('assets.categories.store') }}" class="grid gap-4 sm:grid-cols-2">
                    @csrf
                    <x-ui.input name="code" :label="__('fixed_assets.category_code')" required />
                    <x-ui.input name="name" :label="__('fixed_assets.category_name')" required />
                    <x-ui.select name="depreciation_method" :label="__('fixed_assets.method')" required>
                        <option value="straight_line">{{ __('fixed_assets.straight_line') }}</option>
                        <option value="declining_balance">{{ __('fixed_assets.declining_balance') }}</option>
                    </x-ui.select>
                    <x-ui.input name="useful_life_months" type="number" min="1" max="1200" value="60" :label="__('fixed_assets.useful_life_months')" required />
                    <x-ui.input name="salvage_percent" type="number" min="0" max="100" step="0.0001" value="0" :label="__('fixed_assets.salvage_percent')" required />
                    <x-ui.input name="asset_account_code" value="AUTO-FIXED-ASSET" :label="__('fixed_assets.asset_account')" required />
                    <x-ui.input name="accumulated_depreciation_account_code" value="AUTO-ACCUM-DEP" :label="__('fixed_assets.accumulated_account')" required />
                    <x-ui.input name="depreciation_expense_account_code" value="AUTO-DEP-EXP" :label="__('fixed_assets.expense_account')" required />
                    <div class="sm:col-span-2 flex justify-end">
                        <x-ui.button type="submit" icon="plus">{{ __('fixed_assets.save_category') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <x-ui.card>
                <x-slot:header>
                    <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('fixed_assets.bulk_depreciation') }}</h2>
                </x-slot:header>

                <form method="POST" action="{{ route('assets.depreciate-all') }}" class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    @csrf
                    <div class="flex-1">
                        <x-ui.input name="through_date" type="date" :value="now()->endOfMonth()->toDateString()" :label="__('fixed_assets.through_date')" required />
                    </div>
                    <x-ui.button type="submit" icon="calculator">{{ __('fixed_assets.run_all') }}</x-ui.button>
                </form>

                @if($categories->isNotEmpty())
                    <div class="mt-5 border-t border-slate-100 pt-4 dark:border-slate-700">
                        <p class="mb-2 text-xs font-medium uppercase tracking-wide text-slate-500">{{ __('fixed_assets.categories') }}</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach($categories as $category)
                                <span class="rounded-full border border-slate-200 px-3 py-1 text-xs text-slate-600 dark:border-slate-700 dark:text-slate-300">
                                    {{ $category->code }} — {{ $category->name }} · {{ $category->useful_life_months }}m
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-ui.card>
        </div>

        <div class="mt-5">
            <x-ui.card>
                <x-slot:header>
                    <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('fixed_assets.new_asset') }}</h2>
                </x-slot:header>

                <form method="POST" action="{{ route('assets.store') }}" class="grid gap-4 md:grid-cols-3 xl:grid-cols-4">
                    @csrf
                    <x-ui.input name="asset_number" :label="__('fixed_assets.asset_number')" required />
                    <x-ui.input name="name" :label="__('fixed_assets.asset_name')" required />
                    <x-ui.input name="serial_number" :label="__('fixed_assets.serial_number')" />
                    <x-ui.input name="location" :label="__('fixed_assets.location')" />

                    <x-ui.select name="asset_category_id" :label="__('fixed_assets.category')" required>
                        @foreach($categories as $category)
                            <option value="{{ $category->id }}"
                                data-life="{{ $category->useful_life_months }}"
                                data-method="{{ $category->depreciation_method }}">
                                {{ $category->code }} — {{ $category->name }}
                            </option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select name="cost_center_id" :label="__('fixed_assets.cost_center')">
                        <option value="">{{ __('fixed_assets.none') }}</option>
                        @foreach($costCenters as $costCenter)
                            <option value="{{ $costCenter->id }}">{{ $costCenter->code }} — {{ $costCenter->name }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input name="acquisition_date" type="date" :value="now()->toDateString()" :label="__('fixed_assets.acquisition_date')" required />
                    <x-ui.input name="in_service_date" type="date" :value="now()->toDateString()" :label="__('fixed_assets.in_service_date')" required />
                    <x-ui.input name="acquisition_cost" type="number" min="0.0001" step="0.0001" :label="__('fixed_assets.acquisition_cost')" required />
                    <x-ui.input name="salvage_value" type="number" min="0" step="0.0001" value="0" :label="__('fixed_assets.salvage_value')" />
                    <x-ui.input name="useful_life_months" type="number" min="1" max="1200" value="60" :label="__('fixed_assets.useful_life_months')" required />

                    <x-ui.select name="depreciation_method" :label="__('fixed_assets.method')" required>
                        <option value="straight_line">{{ __('fixed_assets.straight_line') }}</option>
                        <option value="declining_balance">{{ __('fixed_assets.declining_balance') }}</option>
                    </x-ui.select>

                    <x-ui.select name="payment_method" :label="__('fixed_assets.payment_method')" required>
                        <option value="cash">{{ __('fixed_assets.cash') }}</option>
                        <option value="bank_transfer">{{ __('fixed_assets.bank_transfer') }}</option>
                        <option value="card">{{ __('fixed_assets.card') }}</option>
                        <option value="other">{{ __('fixed_assets.other') }}</option>
                    </x-ui.select>

                    <div class="md:col-span-2 xl:col-span-3">
                        <x-ui.input name="notes" :label="__('fixed_assets.notes')" />
                    </div>

                    <div class="flex items-end justify-end">
                        <x-ui.button type="submit" icon="plus" :disabled="$categories->isEmpty()">
                            {{ __('fixed_assets.save_asset') }}
                        </x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        </div>
    @endcan

    <div class="mt-5">
        <x-ui.card>
            <x-slot:header>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('fixed_assets.asset_register') }}</h2>
                    <form method="GET" action="{{ route('assets.index') }}" class="flex items-end gap-2">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-500">{{ __('fixed_assets.status') }}</label>
                            <select name="status" class="rounded-md border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-900">
                                <option value="">{{ __('fixed_assets.all_statuses') }}</option>
                                <option value="active" @selected($status === 'active')>{{ __('fixed_assets.active') }}</option>
                                <option value="disposed" @selected($status === 'disposed')>{{ __('fixed_assets.disposed') }}</option>
                            </select>
                        </div>
                        <x-ui.button type="submit" variant="secondary" size="sm">{{ __('fixed_assets.filter') }}</x-ui.button>
                    </form>
                </div>
            </x-slot:header>

            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-slot:head>
                        <tr>
                            <x-ui.th>{{ __('fixed_assets.asset_number') }}</x-ui.th>
                            <x-ui.th>{{ __('fixed_assets.asset_name') }}</x-ui.th>
                            <x-ui.th>{{ __('fixed_assets.category') }}</x-ui.th>
                            <x-ui.th>{{ __('fixed_assets.acquisition_cost') }}</x-ui.th>
                            <x-ui.th>{{ __('fixed_assets.summary.accumulated_depreciation') }}</x-ui.th>
                            <x-ui.th>{{ __('fixed_assets.summary.book_value') }}</x-ui.th>
                            <x-ui.th>{{ __('fixed_assets.status') }}</x-ui.th>
                            <x-ui.th></x-ui.th>
                        </tr>
                    </x-slot:head>
                    @forelse($assets as $asset)
                        <tr>
                            <x-ui.td>{{ $asset->asset_number }}</x-ui.td>
                            <x-ui.td>{{ $asset->name }}</x-ui.td>
                            <x-ui.td>{{ $asset->category?->name }}</x-ui.td>
                            <x-ui.td>{{ number_format((float) $asset->acquisition_cost, 2) }}</x-ui.td>
                            <x-ui.td>{{ number_format((float) $asset->accumulated_depreciation, 2) }}</x-ui.td>
                            <x-ui.td>{{ number_format((float) $asset->book_value, 2) }}</x-ui.td>
                            <x-ui.td><x-ui.badge :tone="$asset->status === 'active' ? 'success' : 'neutral'">{{ __('fixed_assets.'.$asset->status) }}</x-ui.badge></x-ui.td>
                            <x-ui.td>
                                <x-ui.button href="{{ route('assets.show', $asset) }}" variant="secondary" size="sm">{{ __('fixed_assets.view') }}</x-ui.button>
                            </x-ui.td>
                        </tr>
                    @empty
                        <tr><x-ui.td colspan="8">{{ __('fixed_assets.no_assets') }}</x-ui.td></tr>
                    @endforelse
                </x-ui.table>
            </div>

            @if($assets->hasPages())
                <div class="mt-4">{{ $assets->links() }}</div>
            @endif
        </x-ui.card>
    </div>
</x-app.page>
@endsection
