@extends('layouts.app')

@section('content')
<x-app.page icon="arrow-path" :title="__('operations.reorder.title')" :subtitle="__('operations.reorder.subtitle')">
    <x-slot:actions>
        <x-ui.button href="{{ route('inventory.index') }}" variant="secondary">{{ __('operations.inventory.title') }}</x-ui.button>
    </x-slot:actions>

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif
    @if ($errors->any())
        <div class="mb-5"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <x-ui.card><div class="text-sm text-slate-500">{{ __('operations.reorder.out_of_stock') }}</div><div class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{{ $summary['out_of_stock'] }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">{{ __('operations.reorder.low') }}</div><div class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{{ $summary['low'] }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">{{ __('operations.reorder.replenishing') }}</div><div class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{{ $summary['replenishing'] }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">{{ __('operations.reorder.ok') }}</div><div class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{{ $summary['ok'] }}</div></x-ui.card>
        <x-ui.card><div class="text-sm text-slate-500">{{ __('operations.reorder.inactive') }}</div><div class="mt-2 text-2xl font-semibold text-slate-900 dark:text-white">{{ $summary['inactive'] }}</div></x-ui.card>
    </div>

    @can('inventory.manage')
        <x-ui.card class="mt-5">
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.reorder.rule_form') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('inventory.reorder.store') }}" class="grid gap-4 lg:grid-cols-6">
                @csrf
                <x-ui.select name="warehouse_id" :label="__('operations.reorder.warehouse')" required>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->code }} — {{ $warehouse->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select name="product_id" :label="__('operations.reorder.product')" required>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}">{{ $product->name }} @if($product->sku) ({{ $product->sku }}) @endif</option>
                    @endforeach
                </x-ui.select>
                <x-ui.select name="product_variant_id" :label="__('products.variants.select')">
                    <option value="">{{ __('products.variants.no_variant') }}</option>
                    @foreach($products as $product)
                        @foreach($product->variants as $variant)
                            <option value="{{ $variant->id }}">{{ $product->name }} — {{ $variant->name }}</option>
                        @endforeach
                    @endforeach
                </x-ui.select>
                <x-ui.input name="reorder_point" type="number" min="0" step="0.0001" :label="__('operations.reorder.reorder_point')" required />
                <x-ui.input name="target_stock" type="number" min="0.0001" step="0.0001" :label="__('operations.reorder.target_stock')" required />
                <div class="flex items-end">
                    <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2.5 text-sm text-slate-700 dark:border-slate-700 dark:text-slate-200">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" checked class="rounded border-slate-300">
                        {{ __('operations.reorder.active') }}
                    </label>
                </div>
                <div class="lg:col-span-6 flex justify-end">
                    <x-ui.button type="submit" icon="plus">{{ __('operations.reorder.save_rule') }}</x-ui.button>
                </div>
            </form>
            <p class="mt-3 text-xs text-slate-500">{{ __('operations.reorder.upsert_help') }}</p>
        </x-ui.card>
    @endcan

    <x-ui.card class="mt-5">
        <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.reorder.planning') }}</h2></x-slot:header>

        <form method="GET" action="{{ route('inventory.reorder.index') }}" class="mb-4 grid gap-3 md:grid-cols-4">
            <x-ui.input name="q" :value="$filters['q']" :label="__('operations.reorder.search')" />
            <x-ui.select name="warehouse_id" :label="__('operations.reorder.warehouse_filter')">
                <option value="">{{ __('operations.reorder.all_warehouses') }}</option>
                @foreach($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}" @selected((int) ($filters['warehouse_id'] ?? 0) === $warehouse->id)>{{ $warehouse->name }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.select name="status" :label="__('operations.reorder.status_filter')">
                <option value="">{{ __('operations.reorder.all_statuses') }}</option>
                @foreach(['out_of_stock','low','replenishing','ok','inactive'] as $status)
                    <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ __('operations.reorder.'.$status) }}</option>
                @endforeach
            </x-ui.select>
            <div class="flex items-end gap-2">
                <x-ui.button type="submit" variant="secondary">{{ __('operations.reorder.filter') }}</x-ui.button>
                <x-ui.button href="{{ route('inventory.reorder.index') }}" variant="secondary">{{ __('operations.reorder.reset') }}</x-ui.button>
            </div>
        </form>

        @can('inventory.manage')
            @can('purchasing.manage')
                <form id="replenishment-pr-form" method="POST" action="{{ route('inventory.reorder.requisition') }}" class="mb-4 flex flex-wrap items-end justify-end gap-3">
                    @csrf
                    <x-ui.input name="needed_by" type="date" :label="__('operations.reorder.needed_by')" />
                    <x-ui.button type="submit" icon="shopping-cart">{{ __('operations.reorder.create_requisition') }}</x-ui.button>
                </form>
                <p class="-mt-2 mb-4 text-xs text-slate-500">{{ __('operations.reorder.requisition_help') }}</p>
            @endcan
        @endcan

        <div class="overflow-x-auto">
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        @can('purchasing.manage')<x-ui.th></x-ui.th>@endcan
                        <x-ui.th>{{ __('operations.reorder.warehouse') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reorder.product') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reorder.current_stock') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reorder.inbound_pipeline') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reorder.projected_stock') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reorder.reorder_point') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reorder.target_stock') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reorder.suggested_quantity') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reorder.planning_cost') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reorder.suggested_value') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.reorder.status') }}</x-ui.th>
                        @can('inventory.manage')<x-ui.th></x-ui.th>@endcan
                    </tr>
                </x-slot:head>

                @forelse($rows as $row)
                    @php($rule = $row['rule'])
                    @php($tone = match($row['status']) { 'out_of_stock' => 'danger', 'low' => 'warning', 'replenishing' => 'brand', 'ok' => 'success', default => 'neutral' })
                    <tr>
                        @can('purchasing.manage')
                            <x-ui.td>
                                @if((float) $row['suggested_quantity'] > 0)
                                    <input form="replenishment-pr-form" type="checkbox" name="rule_ids[]" value="{{ $rule->id }}" class="rounded border-slate-300">
                                @endif
                            </x-ui.td>
                        @endcan
                        <x-ui.td>{{ $rule->warehouse?->name }}</x-ui.td>
                        <x-ui.td>
                            {{ $rule->product?->name }}
                            @if($rule->variant)<span class="text-slate-500">— {{ $rule->variant->name }}</span>@endif
                        </x-ui.td>
                        <x-ui.td>{{ $row['current_quantity'] }}</x-ui.td>
                        <x-ui.td>{{ $row['pipeline_quantity'] }}</x-ui.td>
                        <x-ui.td>{{ $row['projected_quantity'] }}</x-ui.td>
                        <x-ui.td>{{ $rule->reorder_point }}</x-ui.td>
                        <x-ui.td>{{ $rule->target_stock }}</x-ui.td>
                        <x-ui.td class="font-medium">{{ $row['suggested_quantity'] }}</x-ui.td>
                        <x-ui.td>{{ $row['planning_unit_cost'] }}</x-ui.td>
                        <x-ui.td>{{ $row['suggested_value'] }}</x-ui.td>
                        <x-ui.td><x-ui.badge :tone="$tone" dot>{{ __('operations.reorder.'.$row['status']) }}</x-ui.badge></x-ui.td>
                        @can('inventory.manage')
                            <x-ui.td>
                                <div class="flex gap-2">
                                    <form method="POST" action="{{ route('inventory.reorder.active', $rule) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="is_active" value="{{ $rule->is_active ? 0 : 1 }}">
                                        <x-ui.button type="submit" size="sm" variant="secondary">
                                            {{ $rule->is_active ? __('operations.reorder.disable') : __('operations.reorder.enable') }}
                                        </x-ui.button>
                                    </form>
                                    <form method="POST" action="{{ route('inventory.reorder.destroy', $rule) }}" onsubmit="return confirm('{{ __('operations.reorder.delete_confirm') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" size="sm" variant="danger">{{ __('operations.reorder.delete') }}</x-ui.button>
                                    </form>
                                </div>
                            </x-ui.td>
                        @endcan
                    </tr>
                @empty
                    <tr><x-ui.td colspan="13">{{ __('operations.reorder.empty') }}</x-ui.td></tr>
                @endforelse
            </x-ui.table>
        </div>

        <p class="mt-4 text-xs text-slate-500">{{ __('operations.reorder.cost_help') }}</p>
    </x-ui.card>
</x-app.page>
@endsection
