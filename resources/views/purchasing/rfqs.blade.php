@extends('layouts.app')

@section('content')
<x-app.page icon="document-text" :title="__('operations.purchasing.rfqs')" :subtitle="__('operations.purchasing.rfqs_help')">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="{{ route('purchasing.requisitions.index') }}" variant="secondary" icon="document-text">
                {{ __('operations.purchasing.requisitions') }}
            </x-ui.button>
            <x-ui.button href="{{ route('purchasing.index') }}" variant="secondary" icon="shopping-cart">
                {{ __('operations.purchasing.purchase_orders') }}
            </x-ui.button>
        </div>
    </x-slot:actions>

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif

    @can('purchasing.manage')
        <x-ui.card>
            <x-slot:header>
                <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.purchasing.new_rfq') }}</h2>
            </x-slot:header>

            <form method="POST" action="{{ route('purchasing.rfqs.store') }}" class="grid gap-4 lg:grid-cols-2">
                @csrf
                <x-ui.select name="purchase_requisition_id" :label="__('operations.purchasing.requisition')" required>
                    <option value="">{{ __('operations.purchasing.select_requisition') }}</option>
                    @foreach($approvedRequisitions as $requisition)
                        <option value="{{ $requisition->id }}">{{ $requisition->number }} — {{ $requisition->purpose ?: $requisition->estimated_total }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input name="issue_date" type="date" :label="__('operations.purchasing.issue_date')" :value="now()->toDateString()" required />
                <x-ui.input name="response_due_date" type="date" :label="__('operations.purchasing.response_due_date')" />
                <x-ui.input name="notes" :label="__('operations.purchasing.notes')" />

                <div class="lg:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('operations.purchasing.invited_suppliers') }}</label>
                    <select name="supplier_ids[]" multiple size="6" class="w-full rounded-lg border-slate-300 dark:border-slate-700 dark:bg-slate-900" required>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->code }} — {{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">{{ __('operations.purchasing.multi_select_suppliers_help') }}</p>
                </div>

                <div class="lg:col-span-2 flex justify-end">
                    <x-ui.button type="submit" icon="plus">{{ __('operations.purchasing.create_rfq') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endcan

    <div class="mt-5 space-y-5">
        @forelse($rfqs as $rfq)
            <x-ui.card>
                <x-slot:header>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="flex items-center gap-2">
                                <h2 class="font-semibold text-slate-900 dark:text-white">{{ $rfq->number }}</h2>
                                <x-ui.badge :tone="$rfq->status === 'awarded' ? 'success' : ($rfq->status === 'open' ? 'warning' : 'neutral')">
                                    {{ __('operations.purchasing.rfq_status_'.$rfq->status) }}
                                </x-ui.badge>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">
                                {{ __('operations.purchasing.requisition') }}: {{ $rfq->requisition?->number }}
                                · {{ __('operations.purchasing.issue_date') }}: {{ $rfq->issue_date?->format('Y-m-d') }}
                                @if($rfq->response_due_date)
                                    · {{ __('operations.purchasing.response_due_date') }}: {{ $rfq->response_due_date->format('Y-m-d') }}
                                @endif
                            </p>
                        </div>

                        @can('purchasing.manage')
                            @if($rfq->status === 'draft')
                                <form method="POST" action="{{ route('purchasing.rfqs.open', $rfq) }}">
                                    @csrf
                                    <x-ui.button type="submit" size="sm">{{ __('operations.purchasing.open_rfq') }}</x-ui.button>
                                </form>
                            @endif
                        @endcan
                    </div>
                </x-slot:header>

                <div class="grid gap-5 xl:grid-cols-3">
                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('operations.purchasing.requested_items') }}</h3>
                        <div class="space-y-2">
                            @foreach($rfq->requisition?->items ?? [] as $item)
                                <div class="rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700">
                                    <div class="font-medium text-slate-900 dark:text-white">
                                        {{ $item->product?->name }}
                                        @if($item->variant) — {{ $item->variant->name }} @endif
                                    </div>
                                    <div class="text-xs text-slate-500">{{ $item->quantity }} × {{ $item->estimated_unit_cost }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('operations.purchasing.invited_suppliers') }}</h3>
                        <div class="space-y-2">
                            @foreach($rfq->invitedSuppliers as $supplier)
                                <div class="flex items-center justify-between rounded-lg border border-slate-200 p-3 text-sm dark:border-slate-700">
                                    <div>
                                        <div class="font-medium text-slate-900 dark:text-white">{{ $supplier->name }}</div>
                                        <div class="text-xs text-slate-500">{{ $supplier->code }}</div>
                                    </div>
                                    <x-ui.badge :tone="$supplier->pivot->responded_at ? 'success' : 'neutral'">
                                        {{ $supplier->pivot->responded_at ? __('operations.purchasing.responded') : __('operations.purchasing.awaiting_response') }}
                                    </x-ui.badge>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    @can('purchasing.manage')
                        @if($rfq->status === 'open')
                            <div>
                                <h3 class="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('operations.purchasing.record_supplier_quote') }}</h3>
                                @php($quotedSupplierIds = $rfq->quotations->pluck('supplier_id')->all())
                                @foreach($rfq->invitedSuppliers->whereNotIn('id', $quotedSupplierIds) as $supplier)
                                    <details class="mb-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                        <summary class="cursor-pointer font-medium text-slate-900 dark:text-white">{{ $supplier->name }}</summary>
                                        <form method="POST" action="{{ route('purchasing.rfqs.quotations.store', $rfq) }}" class="mt-3 space-y-3">
                                            @csrf
                                            <input type="hidden" name="supplier_id" value="{{ $supplier->id }}">
                                            <div class="grid gap-3 sm:grid-cols-2">
                                                <x-ui.input name="supplier_reference" :label="__('operations.purchasing.supplier_reference')" />
                                                <x-ui.input name="quote_date" type="date" :label="__('operations.purchasing.quote_date')" :value="now()->toDateString()" required />
                                                <x-ui.input name="valid_until" type="date" :label="__('operations.purchasing.valid_until')" />
                                                <x-ui.input name="notes" :label="__('operations.purchasing.notes')" />
                                            </div>

                                            @foreach($rfq->requisition?->items ?? [] as $quoteIndex => $item)
                                                <div class="grid gap-2 rounded-lg bg-slate-50 p-3 dark:bg-slate-900 sm:grid-cols-2">
                                                    <div class="text-sm">
                                                        <div class="font-medium">{{ $item->product?->name }}</div>
                                                        <div class="text-xs text-slate-500">{{ __('operations.purchasing.quantity') }}: {{ $item->quantity }}</div>
                                                    </div>
                                                    <div>
                                                        <input type="hidden" name="items[{{ $quoteIndex }}][purchase_requisition_item_id]" value="{{ $item->id }}">
                                                        <label class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-300">{{ __('operations.purchasing.quoted_unit_cost') }}</label>
                                                        <input name="items[{{ $quoteIndex }}][unit_cost]" type="number" step="0.0001" min="0" class="w-full rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950" required>
                                                    </div>
                                                </div>
                                            @endforeach

                                            <div class="flex justify-end">
                                                <x-ui.button type="submit" size="sm">{{ __('operations.purchasing.save_quote') }}</x-ui.button>
                                            </div>
                                        </form>
                                    </details>
                                @endforeach
                            </div>
                        @endif
                    @endcan
                </div>

                <div class="mt-5">
                    <h3 class="mb-2 text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('operations.purchasing.quote_comparison') }}</h3>
                    <div class="overflow-x-auto">
                        <x-ui.table>
                            <x-slot:head>
                                <tr>
                                    <x-ui.th>{{ __('operations.purchasing.supplier') }}</x-ui.th>
                                    <x-ui.th>{{ __('operations.purchasing.internal_quote_number') }}</x-ui.th>
                                    <x-ui.th>{{ __('operations.purchasing.supplier_reference') }}</x-ui.th>
                                    <x-ui.th>{{ __('operations.purchasing.total') }}</x-ui.th>
                                    <x-ui.th>{{ __('operations.purchasing.delta_from_lowest') }}</x-ui.th>
                                    <x-ui.th>{{ __('operations.purchasing.status') }}</x-ui.th>
                                    <x-ui.th></x-ui.th>
                                </tr>
                            </x-slot:head>

                            @forelse($comparisons[$rfq->id] ?? collect() as $row)
                                @php($quote = $row['quotation'])
                                <tr>
                                    <x-ui.td>{{ $quote->supplier?->name }}</x-ui.td>
                                    <x-ui.td>{{ $quote->number }}</x-ui.td>
                                    <x-ui.td>{{ $quote->supplier_reference ?: '—' }}</x-ui.td>
                                    <x-ui.td>{{ $quote->total }}</x-ui.td>
                                    <x-ui.td>
                                        {{ $row['delta_from_lowest'] }}
                                        @if($row['is_lowest'])
                                            <x-ui.badge tone="success">{{ __('operations.purchasing.lowest_price') }}</x-ui.badge>
                                        @endif
                                    </x-ui.td>
                                    <x-ui.td>{{ __('operations.purchasing.quote_status_'.$quote->status) }}</x-ui.td>
                                    <x-ui.td>
                                        @can('purchasing.manage')
                                            @if($rfq->status === 'open' && $quote->status === 'received')
                                                <form method="POST" action="{{ route('purchasing.rfqs.quotations.award', [$rfq, $quote]) }}">
                                                    @csrf
                                                    <x-ui.button type="submit" size="sm" variant="secondary">{{ __('operations.purchasing.select_supplier') }}</x-ui.button>
                                                </form>
                                            @elseif($quote->status === 'selected')
                                                @if($quote->purchaseOrder)
                                                    <div class="text-xs text-slate-500">
                                                        {{ __('operations.purchasing.purchase_order') }}:
                                                        <span class="font-medium text-slate-900 dark:text-white">{{ $quote->purchaseOrder->number }}</span>
                                                    </div>
                                                @elseif($rfq->status === 'awarded')
                                                    <details class="min-w-64">
                                                        <summary class="cursor-pointer text-sm font-medium text-blue-600 dark:text-blue-400">
                                                            {{ __('operations.purchasing.create_purchase_order') }}
                                                        </summary>
                                                        <form method="POST" action="{{ route('purchasing.rfqs.quotations.purchase-order', [$rfq, $quote]) }}" class="mt-2 space-y-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                                                            @csrf
                                                            <x-ui.input name="order_date" type="date" :label="__('operations.purchasing.order_date')" :value="now()->toDateString()" required />
                                                            <x-ui.input name="expected_date" type="date" :label="__('operations.purchasing.expected_date')" />
                                                            <x-ui.input name="notes" :label="__('operations.purchasing.notes')" />
                                                            <div class="flex justify-end">
                                                                <x-ui.button type="submit" size="sm">{{ __('operations.purchasing.create_purchase_order') }}</x-ui.button>
                                                            </div>
                                                        </form>
                                                    </details>
                                                @endif
                                            @endif
                                        @endcan
                                    </x-ui.td>
                                </tr>
                            @empty
                                <tr><x-ui.td colspan="7">{{ __('operations.purchasing.no_supplier_quotes') }}</x-ui.td></tr>
                            @endforelse
                        </x-ui.table>
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card>{{ __('operations.purchasing.no_rfqs') }}</x-ui.card>
        @endforelse
    </div>
</x-app.page>
@endsection
