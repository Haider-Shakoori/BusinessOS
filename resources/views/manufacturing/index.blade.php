@extends('layouts.app')

@section('content')
<x-app.page icon="factory" :title="__('operations.manufacturing.title')" :subtitle="__('operations.manufacturing.subtitle')">
    @if (session('status')) <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div> @endif

    <div class="grid gap-5 xl:grid-cols-2">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.manufacturing.new_bom') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('manufacturing.boms.store') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-ui.select name="product_id" :label="__('operations.manufacturing.finished_product')" required>
                    @foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.input name="code" :label="__('operations.manufacturing.code')" required />
                <x-ui.input name="version" :label="__('operations.manufacturing.version')" value="1" required />
                <x-ui.select name="material_product_id" :label="__('operations.manufacturing.material')" required>
                    @foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.input name="quantity" type="number" step="0.0001" min="0.0001" :label="__('operations.manufacturing.quantity')" required />
                <x-ui.input name="wastage_percent" type="number" step="0.0001" min="0" max="100" :label="__('operations.manufacturing.wastage')" value="0" />
                <div class="sm:col-span-2"><x-ui.input name="notes" :label="__('operations.manufacturing.notes')" /></div>
                <div class="sm:col-span-2 flex justify-end"><x-ui.button type="submit" icon="plus">{{ __('operations.manufacturing.new_bom') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.manufacturing.new_order') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('manufacturing.orders.store') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-ui.input name="number" :label="__('operations.manufacturing.number')" required />
                <x-ui.select name="product_id" :label="__('operations.manufacturing.product')" required>
                    @foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.select name="bom_id" :label="__('operations.manufacturing.bom')">
                    <option value="">—</option>
                    @foreach($boms as $bom)<option value="{{ $bom->id }}">{{ $bom->code }} — {{ $bom->product?->name }}</option>@endforeach
                </x-ui.select>
                <x-ui.input name="planned_quantity" type="number" step="0.0001" min="0.0001" :label="__('operations.manufacturing.planned_quantity')" required />
                <x-ui.input name="start_date" type="date" :label="__('operations.manufacturing.start_date')" />
                <x-ui.input name="due_date" type="date" :label="__('operations.manufacturing.due_date')" />
                <div class="sm:col-span-2 flex justify-end"><x-ui.button type="submit" icon="plus">{{ __('operations.manufacturing.new_order') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.manufacturing.boms') }}</h2></x-slot:header>
            <div class="space-y-3">
                @forelse($boms as $bom)
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <div class="font-medium text-slate-900 dark:text-white">{{ $bom->code }} · {{ $bom->product?->name }}</div>
                        <div class="mt-2 text-sm text-slate-500">
                            @foreach($bom->items as $item)
                                <div>{{ $item->material?->name }} — {{ $item->quantity }} @if((float)$item->wastage_percent > 0) (+{{ $item->wastage_percent }}%) @endif</div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('operations.manufacturing.no_boms') }}</p>
                @endforelse
            </div>
        </x-ui.card>

        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.manufacturing.orders') }}</h2></x-slot:header>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-slot:head><tr><x-ui.th>{{ __('operations.manufacturing.number') }}</x-ui.th><x-ui.th>{{ __('operations.manufacturing.product') }}</x-ui.th><x-ui.th>{{ __('operations.manufacturing.planned_quantity') }}</x-ui.th><x-ui.th>{{ __('operations.manufacturing.status') }}</x-ui.th></tr></x-slot:head>
                    @forelse($orders as $order)
                        <tr><x-ui.td>{{ $order->number }}</x-ui.td><x-ui.td>{{ $order->product?->name }}</x-ui.td><x-ui.td>{{ $order->planned_quantity }}</x-ui.td><x-ui.td>{{ __('operations.manufacturing.'.$order->status) }}</x-ui.td></tr>
                    @empty
                        <tr><x-ui.td colspan="4">{{ __('operations.manufacturing.no_orders') }}</x-ui.td></tr>
                    @endforelse
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>
</x-app.page>
@endsection
