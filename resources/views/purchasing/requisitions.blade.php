@extends('layouts.app')

@section('content')
<x-app.page icon="shopping-cart" :title="__('operations.purchasing.requisitions')" :subtitle="__('operations.purchasing.requisitions_help')">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="{{ route('purchasing.rfqs.index') }}" variant="secondary" icon="document-text">
                {{ __('operations.purchasing.rfqs') }}
            </x-ui.button>
            <x-ui.button href="{{ route('purchasing.index') }}" variant="secondary" icon="arrow-left">
                {{ __('operations.purchasing.purchase_orders') }}
            </x-ui.button>
        </div>
    </x-slot:actions>

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif

    <x-ui.card>
        <x-slot:header>
            <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.purchasing.new_requisition') }}</h2>
        </x-slot:header>

        <form method="POST" action="{{ route('purchasing.requisitions.store') }}" class="space-y-5" x-data="{ rows: [0], next: 1 }">
            @csrf
            <div class="grid gap-4 md:grid-cols-3">
                <x-ui.input name="request_date" type="date" :label="__('operations.purchasing.request_date')" :value="now()->toDateString()" required />
                <x-ui.input name="needed_by" type="date" :label="__('operations.purchasing.needed_by')" />
                <x-ui.input name="purpose" :label="__('operations.purchasing.purpose')" />
            </div>

            <div class="space-y-3">
                <template x-for="(row, index) in rows" :key="row">
                    <div class="grid gap-3 rounded-lg border border-slate-200 p-4 dark:border-slate-700 md:grid-cols-12">
                        <div class="md:col-span-3">
                            <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('operations.purchasing.product') }}</label>
                            <select :name="'items['+index+'][product_id]'" class="w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900" required>
                                <option value="">{{ __('operations.purchasing.select_product') }}</option>
                                @foreach($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-3">
                            <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('products.variants.select') }}</label>
                            <select :name="'items['+index+'][product_variant_id]'" class="w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900">
                                <option value="">{{ __('products.variants.no_variant') }}</option>
                                @foreach($products as $product)
                                    @foreach($product->variants as $variant)
                                        <option value="{{ $variant->id }}">{{ $product->name }} — {{ $variant->name }}</option>
                                    @endforeach
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('operations.purchasing.quantity') }}</label>
                            <input :name="'items['+index+'][quantity]'" type="number" step="0.0001" min="0.0001" class="w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900" required>
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('operations.purchasing.estimated_unit_cost') }}</label>
                            <input :name="'items['+index+'][estimated_unit_cost]'" type="number" step="0.0001" min="0" class="w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900" required>
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('operations.purchasing.description') }}</label>
                            <div class="flex gap-2">
                                <input :name="'items['+index+'][description]'" class="min-w-0 flex-1 rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900">
                                <button type="button" @click="rows.splice(index, 1)" x-show="rows.length > 1" class="rounded-lg border border-slate-300 px-3 text-sm dark:border-slate-700">×</button>
                            </div>
                        </div>
                    </div>
                </template>
                <button type="button" @click="rows.push(next++)" class="text-sm font-medium text-blue-600 hover:underline dark:text-blue-400">
                    + {{ __('operations.purchasing.add_line') }}
                </button>
            </div>

            <div class="flex justify-end">
                <x-ui.button type="submit" icon="plus">{{ __('operations.purchasing.create_requisition') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card class="mt-5">
        <x-slot:header>
            <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.purchasing.requisition_history') }}</h2>
        </x-slot:header>

        <div class="overflow-x-auto">
            <x-ui.table>
                <x-slot:head>
                    <tr>
                        <x-ui.th>{{ __('operations.purchasing.number') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.purchasing.request_date') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.purchasing.requested_by') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.purchasing.items') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.purchasing.estimated_total') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.purchasing.status') }}</x-ui.th>
                        <x-ui.th>{{ __('operations.purchasing.actions') }}</x-ui.th>
                    </tr>
                </x-slot:head>

                @forelse($requisitions as $requisition)
                    <tr>
                        <x-ui.td>
                            <div class="font-medium">{{ $requisition->number }}</div>
                            @if($requisition->purpose)<div class="text-xs text-slate-500">{{ $requisition->purpose }}</div>@endif
                        </x-ui.td>
                        <x-ui.td>{{ $requisition->request_date?->format('Y-m-d') }}</x-ui.td>
                        <x-ui.td>{{ $requisition->requester?->name ?? '—' }}</x-ui.td>
                        <x-ui.td>
                            <div class="space-y-1 text-xs">
                                @foreach($requisition->items as $item)
                                    <div>{{ $item->product?->name }}@if($item->variant) — {{ $item->variant->name }}@endif × {{ $item->quantity }}</div>
                                @endforeach
                            </div>
                        </x-ui.td>
                        <x-ui.td>{{ $requisition->estimated_total }}</x-ui.td>
                        <x-ui.td>
                            <x-ui.badge :tone="$requisition->status === 'approved' ? 'success' : ($requisition->status === 'rejected' ? 'danger' : ($requisition->status === 'submitted' ? 'warning' : 'neutral'))">
                                {{ __('operations.purchasing.requisition_status_'.$requisition->status) }}
                            </x-ui.badge>
                        </x-ui.td>
                        <x-ui.td>
                            @can('purchasing.manage')
                                <div class="flex min-w-64 flex-wrap gap-2">
                                    @if($requisition->status === 'draft')
                                        <form method="POST" action="{{ route('purchasing.requisitions.submit', $requisition) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm">{{ __('operations.purchasing.submit') }}</x-ui.button>
                                        </form>
                                    @elseif($requisition->status === 'submitted')
                                        <form method="POST" action="{{ route('purchasing.requisitions.approve', $requisition) }}">
                                            @csrf
                                            <x-ui.button type="submit" size="sm">{{ __('operations.purchasing.approve') }}</x-ui.button>
                                        </form>
                                        <form method="POST" action="{{ route('purchasing.requisitions.reject', $requisition) }}" class="flex items-end gap-2">
                                            @csrf
                                            <x-ui.input name="rejection_reason" :label="__('operations.purchasing.rejection_reason')" required />
                                            <x-ui.button type="submit" size="sm" variant="secondary">{{ __('operations.purchasing.reject') }}</x-ui.button>
                                        </form>
                                    @elseif($requisition->status === 'approved')
                                        <div class="text-xs text-slate-500">{{ __('operations.purchasing.approved_by') }}: {{ $requisition->approver?->name ?? '—' }}</div>
                                    @else
                                        <div class="text-xs text-slate-500">{{ $requisition->rejection_reason }}</div>
                                    @endif
                                </div>
                            @endcan
                        </x-ui.td>
                    </tr>
                @empty
                    <tr><x-ui.td colspan="7">{{ __('operations.purchasing.no_requisitions') }}</x-ui.td></tr>
                @endforelse
            </x-ui.table>
        </div>
    </x-ui.card>
</x-app.page>
@endsection
