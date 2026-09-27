@extends('layouts.app')

@section('content')
<x-app.page icon="chart-bar" :title="__('operations.accounting.budget_variance')" :subtitle="$budget->name">
    <x-slot:actions>
        <x-ui.button href="{{ route('accounting.index') }}" variant="secondary">{{ __('operations.accounting.back_to_accounting') }}</x-ui.button>
    </x-slot:actions>

    <x-ui.card>
        <div class="mb-4 flex flex-wrap justify-between gap-3 text-sm">
            <span>{{ $budget->start_date->format('Y-m-d') }} — {{ $budget->end_date->format('Y-m-d') }}</span>
            <span>{{ __('operations.accounting.status') }}: {{ $budget->status }}</span>
        </div>
        <div class="overflow-x-auto">
            <x-ui.table>
                <x-slot:head><tr>
                    <x-ui.th>{{ __('operations.accounting.account') }}</x-ui.th>
                    <x-ui.th>{{ __('operations.accounting.cost_center') }}</x-ui.th>
                    <x-ui.th>{{ __('operations.accounting.budget') }}</x-ui.th>
                    <x-ui.th>{{ __('operations.accounting.actual') }}</x-ui.th>
                    <x-ui.th>{{ __('operations.accounting.variance') }}</x-ui.th>
                    <x-ui.th>{{ __('operations.accounting.variance_percent') }}</x-ui.th>
                </tr></x-slot:head>
                @forelse($rows as $row)
                    <tr>
                        <x-ui.td>{{ $row['account']->code }} — {{ $row['account']->name }}</x-ui.td>
                        <x-ui.td>{{ $row['cost_center'] ? $row['cost_center']->code.' — '.$row['cost_center']->name : __('operations.accounting.all_cost_centers') }}</x-ui.td>
                        <x-ui.td>{{ number_format((float) $row['budget'], 2) }}</x-ui.td>
                        <x-ui.td>{{ number_format((float) $row['actual'], 2) }}</x-ui.td>
                        <x-ui.td>{{ number_format((float) $row['variance'], 2) }}</x-ui.td>
                        <x-ui.td>{{ $row['variance_percent'] === null ? '—' : number_format($row['variance_percent'], 2).'%' }}</x-ui.td>
                    </tr>
                @empty
                    <tr><x-ui.td colspan="6">{{ __('operations.accounting.no_budget_lines') }}</x-ui.td></tr>
                @endforelse
            </x-ui.table>
        </div>
    </x-ui.card>
</x-app.page>
@endsection
