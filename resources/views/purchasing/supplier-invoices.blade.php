@extends('layouts.app')

@section('content')
<x-app.page icon="document-text" :title="__('operations.purchasing.supplier_invoices')" :subtitle="__('operations.purchasing.supplier_invoices_help')">
    <x-slot:actions>
        <div class="flex flex-wrap gap-2">
            <x-ui.button href="{{ route('purchasing.index') }}" variant="secondary" icon="shopping-cart">
                {{ __('operations.purchasing.purchase_orders') }}
            </x-ui.button>
            <x-ui.button href="{{ route('purchasing.rfqs.index') }}" variant="secondary" icon="document-text">
                {{ __('operations.purchasing.rfqs') }}
            </x-ui.button>
        </div>
    </x-slot:actions>

    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif

    @if ($errors->any())
        <div class="mb-5">
            <x-ui.alert type="error">
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
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white">{{ __('operations.purchasing.invoice_received_goods') }}</h2>

            @forelse($orders as $order)
                @php($available = $availableByOrder->get($order->id, collect()))
                @php($hasAvailable = $available->contains(fn ($qty) => AppSupportDecimal::gt((string) $qty, '0')))

                @if($hasAvailable)
                    <x-ui.card>
                        <x-slot:header>
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="font-semibold text-slate-900 dark:text-white">{{ $order->number }}</h3>
                                        <x-ui.badge :tone="$order->status === 'received' ? 'success' : 'warning'">
                                            {{ ucfirst(str_replace('_', ' ', $order->status)) }}
                                        </x-ui.badge>
                                    </div>
                                    <p class="mt-1 text-sm text-slate-500">
                                        {{ $order->supplier?->name }}
                                        · {{ __('operations.purchasing.order_date') }}: {{ $order->order_date?->format('Y-m-d') }}
                                        · {{ __('operations.purchasing.total') }}: {{ $order->total }}
                                    </p>
                                </div>
                                <div class="text-xs text-slate-500">
                                    {{ __('operations.purchasing.three_way_match_source') }}
                                </div>
                            </div>
                        </x-slot:header>

                        <form method="POST" action="{{ route('purchasing.supplier-invoices.store') }}" class="space-y-4">
                            @csrf
                            <input type="hidden" name="purchase_order_id" value="{{ $order->id }}">

                            <div class="grid gap-3 md:grid-cols-4">
                                <x-ui.input
                                    name="supplier_invoice_number"
                                    :label="__('operations.purchasing.supplier_invoice_number')"
                                    required
                                />
                                <x-ui.input
                                    name="invoice_date"
                                    type="date"
                                    :label="__('operations.purchasing.invoice_date')"
                                    :value="now()->toDateString()"
                                    required
                                />
                                <x-ui.input
                                    name="due_date"
                                    type="date"
                                    :label="__('operations.purchasing.due_date')"
                                />
                                <x-ui.input
                                    name="notes"
                                    :label="__('operations.purchasing.notes')"
                                />
                            </div>

                            <div class="overflow-x-auto">
                                <x-ui.table>
                                    <x-slot:head>
                                        <tr>
                                            <x-ui.th>{{ __('operations.purchasing.product') }}</x-ui.th>
                                            <x-ui.th>{{ __('operations.purchasing.received_uninvoiced') }}</x-ui.th>
                                            <x-ui.th>{{ __('operations.purchasing.po_unit_cost') }}</x-ui.th>
                                            <x-ui.th>{{ __('operations.purchasing.invoice_quantity') }}</x-ui.th>
                                            <x-ui.th>{{ __('operations.purchasing.invoice_unit_cost') }}</x-ui.th>
                                        </tr>
                                    </x-slot:head>

                                    @foreach($order->items as $lineIndex => $item)
                                        @php($availableQty = $available->get($item->id, '0.0000'))
                                        <tr>
                                            <x-ui.td>
                                                {{ $item->product?->name }}
                                                @if($item->variant)
                                                    <span class="text-slate-500">— {{ $item->variant->name }}</span>
                                                @endif
                                            </x-ui.td>
                                            <x-ui.td>{{ $availableQty }}</x-ui.td>
                                            <x-ui.td>{{ $item->unit_cost }}</x-ui.td>
                                            <x-ui.td>
                                                <input
                                                    type="hidden"
                                                    name="items[{{ $lineIndex }}][purchase_order_item_id]"
                                                    value="{{ $item->id }}"
                                                >
                                                <input
                                                    name="items[{{ $lineIndex }}][quantity]"
                                                    type="number"
                                                    step="0.0001"
                                                    min="0"
                                                    max="{{ $availableQty }}"
                                                    value="{{ $availableQty }}"
                                                    class="w-36 rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950"
                                                    {{ AppSupportDecimal::gt((string) $availableQty, '0') ? '' : 'disabled' }}
                                                >
                                            </x-ui.td>
                                            <x-ui.td>
                                                <input
                                                    name="items[{{ $lineIndex }}][unit_cost]"
                                                    type="number"
                                                    step="0.0001"
                                                    min="0"
                                                    value="{{ $item->unit_cost }}"
                                                    class="w-36 rounded-lg border-slate-300 text-sm dark:border-slate-700 dark:bg-slate-950"
                                                    {{ AppSupportDecimal::gt((string) $availableQty, '0') ? '' : 'disabled' }}
                                                >
                                            </x-ui.td>
                                        </tr>
                                    @endforeach
                                </x-ui.table>
                            </div>

                            <p class="text-xs text-slate-500">
                                {{ __('operations.purchasing.supplier_invoice_match_hint') }}
                            </p>

                            <div class="flex justify-end">
                                <x-ui.button type="submit" icon="plus">
                                    {{ __('operations.purchasing.create_supplier_invoice') }}
                                </x-ui.button>
                            </div>
                        </form>
                    </x-ui.card>
                @endif
            @empty
                <x-ui.card>{{ __('operations.purchasing.no_received_orders_for_invoicing') }}</x-ui.card>
            @endforelse
        </div>
    @endcan

    <div class="mt-6">
        <h2 class="mb-3 text-lg font-semibold text-slate-900 dark:text-white">{{ __('operations.purchasing.supplier_invoice_history') }}</h2>

        <div class="space-y-4">
            @forelse($invoices as $invoice)
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="font-semibold text-slate-900 dark:text-white">{{ $invoice->number }}</h3>
                                    <x-ui.badge :tone="$invoice->status === 'approved' ? 'success' : ($invoice->status === 'submitted' ? 'warning' : ($invoice->status === 'rejected' ? 'danger' : 'neutral'))">
                                        {{ __('operations.purchasing.supplier_invoice_status_'.$invoice->status) }}
                                    </x-ui.badge>
                                    <x-ui.badge :tone="$invoice->match_status === 'matched' ? 'success' : 'warning'">
                                        {{ __('operations.purchasing.match_status_'.$invoice->match_status) }}
                                    </x-ui.badge>
                                </div>
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $invoice->supplier?->name }}
                                    · {{ __('operations.purchasing.supplier_invoice_number') }}: {{ $invoice->supplier_invoice_number }}
                                    · {{ __('operations.purchasing.purchase_order') }}: {{ $invoice->purchaseOrder?->number }}
                                </p>
                            </div>
                            <div class="text-end text-xs text-slate-500">
                                <div>{{ __('operations.purchasing.invoice_date') }}: {{ $invoice->invoice_date?->format('Y-m-d') }}</div>
                                @if($invoice->due_date)
                                    <div>{{ __('operations.purchasing.due_date') }}: {{ $invoice->due_date->format('Y-m-d') }}</div>
                                @endif
                            </div>
                        </div>
                    </x-slot:header>

                    <div class="grid gap-4 lg:grid-cols-4">
                        <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                            <div class="text-xs text-slate-500">{{ __('operations.purchasing.invoice_total') }}</div>
                            <div class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $invoice->total }}</div>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                            <div class="text-xs text-slate-500">{{ __('operations.purchasing.po_basis_total') }}</div>
                            <div class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $invoice->po_basis_total }}</div>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                            <div class="text-xs text-slate-500">{{ __('operations.purchasing.price_variance') }}</div>
                            <div class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $invoice->price_variance_total }}</div>
                        </div>
                        <div class="rounded-lg bg-slate-50 p-3 dark:bg-slate-900">
                            <div class="text-xs text-slate-500">{{ __('operations.purchasing.created_by') }}</div>
                            <div class="mt-1 font-semibold text-slate-900 dark:text-white">{{ $invoice->creator?->name ?: '—' }}</div>
                        </div>
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <x-ui.table>
                            <x-slot:head>
                                <tr>
                                    <x-ui.th>{{ __('operations.purchasing.product') }}</x-ui.th>
                                    <x-ui.th>{{ __('operations.purchasing.quantity') }}</x-ui.th>
                                    <x-ui.th>{{ __('operations.purchasing.po_unit_cost') }}</x-ui.th>
                                    <x-ui.th>{{ __('operations.purchasing.invoice_unit_cost') }}</x-ui.th>
                                    <x-ui.th>{{ __('operations.purchasing.price_variance') }}</x-ui.th>
                                    <x-ui.th>{{ __('operations.purchasing.total') }}</x-ui.th>
                                </tr>
                            </x-slot:head>

                            @foreach($invoice->items as $item)
                                <tr>
                                    <x-ui.td>
                                        {{ $item->product?->name }}
                                        @if($item->variant)
                                            <span class="text-slate-500">— {{ $item->variant->name }}</span>
                                        @endif
                                    </x-ui.td>
                                    <x-ui.td>{{ $item->quantity }}</x-ui.td>
                                    <x-ui.td>{{ $item->po_unit_cost }}</x-ui.td>
                                    <x-ui.td>{{ $item->unit_cost }}</x-ui.td>
                                    <x-ui.td>{{ $item->price_variance }}</x-ui.td>
                                    <x-ui.td>{{ $item->line_total }}</x-ui.td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </div>

                    @if($invoice->match_override_reason)
                        <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                            <div class="font-medium">{{ __('operations.purchasing.match_override') }}</div>
                            <div class="mt-1">{{ $invoice->match_override_reason }}</div>
                            @if($invoice->matchOverrider)
                                <div class="mt-1 text-xs">{{ $invoice->matchOverrider->name }} · {{ $invoice->match_override_at?->format('Y-m-d H:i') }}</div>
                            @endif
                        </div>
                    @endif

                    @if($invoice->rejection_reason)
                        <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200">
                            <div class="font-medium">{{ __('operations.purchasing.rejection_reason') }}</div>
                            <div class="mt-1">{{ $invoice->rejection_reason }}</div>
                        </div>
                    @endif

                    @can('purchasing.manage')
                        <div class="mt-4 flex flex-wrap items-start justify-end gap-3">
                            @if($invoice->status === 'draft')
                                <form method="POST" action="{{ route('purchasing.supplier-invoices.submit', $invoice) }}">
                                    @csrf
                                    <x-ui.button type="submit" size="sm">{{ __('operations.purchasing.submit_for_approval') }}</x-ui.button>
                                </form>
                            @elseif($invoice->status === 'submitted')
                                <form method="POST" action="{{ route('purchasing.supplier-invoices.approve', $invoice) }}" class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    @if($invoice->match_status !== 'matched')
                                        <x-ui.input
                                            name="match_override_reason"
                                            :label="__('operations.purchasing.match_override_reason')"
                                            required
                                        />
                                    @endif
                                    <x-ui.button type="submit" size="sm">{{ __('operations.purchasing.approve') }}</x-ui.button>
                                </form>

                                <form method="POST" action="{{ route('purchasing.supplier-invoices.reject', $invoice) }}" class="flex flex-wrap items-end gap-2">
                                    @csrf
                                    <x-ui.input
                                        name="rejection_reason"
                                        :label="__('operations.purchasing.rejection_reason')"
                                        required
                                    />
                                    <x-ui.button type="submit" size="sm" variant="secondary">{{ __('operations.purchasing.reject') }}</x-ui.button>
                                </form>
                            @endif
                        </div>
                    @endcan
                </x-ui.card>
            @empty
                <x-ui.card>{{ __('operations.purchasing.no_supplier_invoices') }}</x-ui.card>
            @endforelse
        </div>
    </div>
</x-app.page>
@endsection
