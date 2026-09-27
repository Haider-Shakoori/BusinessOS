@extends('layouts.app')

@section('content')
<x-app.page icon="banknotes" :title="__('operations.purchasing.landed_costs')" :subtitle="__('operations.purchasing.landed_costs_help')">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="{{ route('purchasing.index') }}" variant="secondary" icon="shopping-cart">
                {{ __('operations.purchasing.purchase_orders') }}
            </x-ui.button>
            <x-ui.button href="{{ route('purchasing.supplier-invoices.index') }}" variant="secondary" icon="document-text">
                {{ __('operations.purchasing.supplier_invoices') }}
            </x-ui.button>
        </div>
    </x-slot:actions>

    @if(session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif

    @if($errors->any())
        <div class="mb-5">
            <x-ui.alert type="danger">
                <ul class="list-disc space-y-1 ps-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </x-ui.alert>
        </div>
    @endif

    @can('purchasing.manage')
        <div class="space-y-4">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">
                {{ __('operations.purchasing.create_landed_cost') }}
            </h2>

            @forelse($receipts as $receipt)
                @php
                    $postedLanded = $receipt->landedCosts
                        ->where('status', 'posted')
                        ->reduce(
                            fn ($carry, $cost) => AppSupportDecimal::add($carry, (string) $cost->total),
                            '0.0000',
                        );
                    $capitalizedValue = AppSupportDecimal::add((string) $receipt->total, $postedLanded);
                @endphp

                <x-ui.card>
                    <x-slot:header>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="font-semibold text-slate-900 dark:text-white">{{ $receipt->number }}</h3>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $receipt->purchaseOrder?->number }}
                                    · {{ $receipt->purchaseOrder?->supplier?->name }}
                                    · {{ $receipt->warehouse?->name }}
                                </p>
                            </div>
                            <div class="text-end text-xs text-slate-500">
                                <div>{{ __('operations.purchasing.receipt_date') }}: {{ $receipt->receipt_date?->format('Y-m-d') }}</div>
                                <div>{{ __('operations.purchasing.receipt_value') }}: {{ $receipt->total }}</div>
                                <div>{{ __('operations.purchasing.posted_landed_cost') }}: {{ $postedLanded }}</div>
                                <div class="font-semibold text-slate-700 dark:text-slate-200">
                                    {{ __('operations.purchasing.capitalized_value') }}: {{ $capitalizedValue }}
                                </div>
                            </div>
                        </div>
                    </x-slot:header>

                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-slot:head>
                                <tr>
                                    <x-ui.th>{{ __('operations.purchasing.product') }}</x-ui.th>
                                    <x-ui.th>{{ __('operations.purchasing.quantity') }}</x-ui.th>
                                    <x-ui.th>{{ __('operations.purchasing.receipt_unit_cost') }}</x-ui.th>
                                    <x-ui.th>{{ __('operations.purchasing.current_landed_unit_cost') }}</x-ui.th>
                                    <x-ui.th>{{ __('operations.purchasing.total') }}</x-ui.th>
                                </tr>
                            </x-slot:head>
                            @foreach($receipt->items as $item)
                                <tr>
                                    <x-ui.td>
                                        {{ $item->product?->name }}
                                        @if($item->variant)
                                            <span class="text-slate-500">— {{ $item->variant->name }}</span>
                                        @endif
                                    </x-ui.td>
                                    <x-ui.td>{{ $item->quantity }}</x-ui.td>
                                    <x-ui.td>{{ $item->unit_cost }}</x-ui.td>
                                    <x-ui.td>{{ $item->stockMovement?->unit_cost ?? $item->unit_cost }}</x-ui.td>
                                    <x-ui.td>{{ $item->line_total }}</x-ui.td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </div>

                    <details
                        class="mt-4 rounded-lg border border-slate-200 p-3 dark:border-slate-700"
                        x-data="{ method: 'value', charges: [{ category: 'freight', description: '', amount: '' }] }"
                    >
                        <summary class="cursor-pointer text-sm font-medium text-blue-600 dark:text-blue-400">
                            {{ __('operations.purchasing.add_landed_cost') }}
                        </summary>

                        <form method="POST" action="{{ route('purchasing.landed-costs.store') }}" class="mt-4 space-y-4">
                            @csrf
                            <input type="hidden" name="goods_receipt_id" value="{{ $receipt->id }}">

                            <div class="grid gap-3 md:grid-cols-3">
                                <x-ui.input
                                    name="cost_date"
                                    type="date"
                                    :label="__('operations.purchasing.cost_date')"
                                    :value="now()->toDateString()"
                                    required
                                />
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-200">
                                        {{ __('operations.purchasing.allocation_method') }}
                                    </label>
                                    <select
                                        name="allocation_method"
                                        x-model="method"
                                        class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950"
                                        required
                                    >
                                        <option value="value">{{ __('operations.purchasing.allocate_by_value') }}</option>
                                        <option value="quantity">{{ __('operations.purchasing.allocate_by_quantity') }}</option>
                                        <option value="manual">{{ __('operations.purchasing.allocate_manually') }}</option>
                                    </select>
                                </div>
                                <x-ui.input name="notes" :label="__('operations.purchasing.notes')" />
                            </div>

                            <div>
                                <div class="mb-2 flex items-center justify-between gap-2">
                                    <h4 class="text-sm font-semibold text-slate-700 dark:text-slate-200">
                                        {{ __('operations.purchasing.landed_cost_charges') }}
                                    </h4>
                                    <button
                                        type="button"
                                        class="text-sm font-medium text-blue-600 dark:text-blue-400"
                                        @click="charges.push({ category: 'freight', description: '', amount: '' })"
                                    >
                                        + {{ __('operations.purchasing.add_charge') }}
                                    </button>
                                </div>

                                <div class="space-y-2">
                                    <template x-for="(charge, index) in charges" :key="index">
                                        <div class="grid gap-2 rounded-lg bg-slate-50 p-3 dark:bg-slate-900 md:grid-cols-4">
                                            <select
                                                :name="'charges['+index+'][category]'"
                                                x-model="charge.category"
                                                class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950"
                                                required
                                            >
                                                <option value="freight">{{ __('operations.purchasing.charge_freight') }}</option>
                                                <option value="customs">{{ __('operations.purchasing.charge_customs') }}</option>
                                                <option value="insurance">{{ __('operations.purchasing.charge_insurance') }}</option>
                                                <option value="handling">{{ __('operations.purchasing.charge_handling') }}</option>
                                                <option value="transport">{{ __('operations.purchasing.charge_transport') }}</option>
                                                <option value="other">{{ __('operations.purchasing.charge_other') }}</option>
                                            </select>
                                            <input
                                                :name="'charges['+index+'][description]'"
                                                x-model="charge.description"
                                                type="text"
                                                maxlength="255"
                                                placeholder="{{ __('operations.purchasing.description') }}"
                                                class="rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950 md:col-span-2"
                                            >
                                            <div class="flex gap-2">
                                                <input
                                                    :name="'charges['+index+'][amount]'"
                                                    x-model="charge.amount"
                                                    type="number"
                                                    step="0.0001"
                                                    min="0.0001"
                                                    placeholder="{{ __('operations.purchasing.amount') }}"
                                                    class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950"
                                                    required
                                                >
                                                <button
                                                    type="button"
                                                    class="rounded-lg px-2 text-xs text-red-600 disabled:opacity-40"
                                                    :disabled="charges.length === 1"
                                                    @click="charges.splice(index, 1)"
                                                >
                                                    {{ __('operations.purchasing.remove') }}
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <div x-show="method === 'manual'" x-cloak>
                                <h4 class="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
                                    {{ __('operations.purchasing.manual_allocations') }}
                                </h4>
                                <div class="grid gap-2 md:grid-cols-2">
                                    @foreach($receipt->items as $manualIndex => $item)
                                        <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                                            <div class="mb-2 text-sm font-medium text-slate-900 dark:text-white">
                                                {{ $item->product?->name }}
                                                @if($item->variant) — {{ $item->variant->name }} @endif
                                            </div>
                                            <input
                                                type="hidden"
                                                name="manual_allocations[{{ $manualIndex }}][goods_receipt_item_id]"
                                                value="{{ $item->id }}"
                                            >
                                            <input
                                                name="manual_allocations[{{ $manualIndex }}][amount]"
                                                type="number"
                                                step="0.0001"
                                                min="0"
                                                value="0"
                                                class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950"
                                                :disabled="method !== 'manual'"
                                            >
                                        </div>
                                    @endforeach
                                </div>
                                <p class="mt-2 text-xs text-slate-500">
                                    {{ __('operations.purchasing.manual_allocation_help') }}
                                </p>
                            </div>

                            <p class="text-xs text-slate-500">
                                {{ __('operations.purchasing.landed_cost_safety_help') }}
                            </p>

                            <div class="flex justify-end">
                                <x-ui.button type="submit" size="sm">{{ __('operations.purchasing.create_draft') }}</x-ui.button>
                            </div>
                        </form>
                    </details>
                </x-ui.card>
            @empty
                <x-ui.card>{{ __('operations.purchasing.no_goods_receipts_for_landed_cost') }}</x-ui.card>
            @endforelse
        </div>
    @endcan

    <div class="mt-6">
        <h2 class="mb-3 text-lg font-semibold text-slate-900 dark:text-white">
            {{ __('operations.purchasing.landed_cost_history') }}
        </h2>

        <div class="space-y-4">
            @forelse($landedCosts as $cost)
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-semibold text-slate-900 dark:text-white">{{ $cost->number }}</h3>
                                    <x-ui.badge :tone="$cost->status === 'posted' ? 'success' : ($cost->status === 'reversed' ? 'neutral' : 'warning')">
                                        {{ __('operations.purchasing.landed_cost_status_'.$cost->status) }}
                                    </x-ui.badge>
                                </div>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $cost->goodsReceipt?->number }}
                                    · {{ $cost->goodsReceipt?->purchaseOrder?->number }}
                                    · {{ $cost->goodsReceipt?->purchaseOrder?->supplier?->name }}
                                </p>
                            </div>
                            <div class="text-end text-xs text-slate-500">
                                <div>{{ __('operations.purchasing.cost_date') }}: {{ $cost->cost_date?->format('Y-m-d') }}</div>
                                <div>{{ __('operations.purchasing.total') }}: {{ $cost->total }}</div>
                                <div>{{ __('operations.purchasing.allocation_method') }}: {{ __('operations.purchasing.allocation_'.$cost->allocation_method) }}</div>
                            </div>
                        </div>
                    </x-slot:header>

                    <div class="grid gap-4 lg:grid-cols-2">
                        <div>
                            <h4 class="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
                                {{ __('operations.purchasing.landed_cost_charges') }}
                            </h4>
                            <div class="space-y-2">
                                @foreach($cost->charges as $charge)
                                    <div class="flex items-center justify-between gap-3 rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-900">
                                        <div>
                                            <span class="font-medium text-slate-900 dark:text-white">
                                                {{ __('operations.purchasing.charge_'.$charge->category) }}
                                            </span>
                                            @if($charge->description)
                                                <div class="text-xs text-slate-500">{{ $charge->description }}</div>
                                            @endif
                                        </div>
                                        <span class="font-semibold">{{ $charge->amount }}</span>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <div>
                            <h4 class="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">
                                {{ __('operations.purchasing.allocation_breakdown') }}
                            </h4>
                            <div class="overflow-x-auto">
                                <x-ui.table>
                                    <x-slot:head>
                                        <tr>
                                            <x-ui.th>{{ __('operations.purchasing.product') }}</x-ui.th>
                                            <x-ui.th>{{ __('operations.purchasing.allocated_amount') }}</x-ui.th>
                                            <x-ui.th>{{ __('operations.purchasing.unit_cost_increment') }}</x-ui.th>
                                            <x-ui.th>{{ __('operations.purchasing.final_unit_cost') }}</x-ui.th>
                                        </tr>
                                    </x-slot:head>
                                    @foreach($cost->allocations as $allocation)
                                        <tr>
                                            <x-ui.td>
                                                {{ $allocation->goodsReceiptItem?->product?->name }}
                                                @if($allocation->goodsReceiptItem?->variant)
                                                    — {{ $allocation->goodsReceiptItem->variant->name }}
                                                @endif
                                            </x-ui.td>
                                            <x-ui.td>{{ $allocation->allocated_amount }}</x-ui.td>
                                            <x-ui.td>{{ $allocation->unit_cost_increment }}</x-ui.td>
                                            <x-ui.td>{{ $allocation->final_unit_cost }}</x-ui.td>
                                        </tr>
                                    @endforeach
                                </x-ui.table>
                            </div>
                        </div>
                    </div>

                    @if($cost->reversal_reason)
                        <div class="mt-4 rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700">
                            <span class="font-medium">{{ __('operations.purchasing.reversal_reason') }}:</span>
                            {{ $cost->reversal_reason }}
                        </div>
                    @endif

                    @can('purchasing.manage')
                        @can('accounting.manage')
                            <div class="mt-4 flex flex-wrap justify-end gap-2">
                                @if($cost->status === 'draft')
                                    <form method="POST" action="{{ route('purchasing.landed-costs.post', $cost) }}">
                                        @csrf
                                        <x-ui.button type="submit" size="sm">{{ __('operations.purchasing.post_landed_cost') }}</x-ui.button>
                                    </form>
                                @elseif($cost->status === 'posted')
                                    <form method="POST" action="{{ route('purchasing.landed-costs.reverse', $cost) }}" class="flex flex-wrap items-end gap-2">
                                        @csrf
                                        <x-ui.input
                                            name="reversal_reason"
                                            :label="__('operations.purchasing.reversal_reason')"
                                            maxlength="1000"
                                        />
                                        <x-ui.button type="submit" size="sm" variant="secondary">
                                            {{ __('operations.purchasing.reverse') }}
                                        </x-ui.button>
                                    </form>
                                @endif
                            </div>
                        @endcan
                    @endcan
                </x-ui.card>
            @empty
                <x-ui.card>{{ __('operations.purchasing.no_landed_costs') }}</x-ui.card>
            @endforelse
        </div>
    </div>
</x-app.page>
@endsection
