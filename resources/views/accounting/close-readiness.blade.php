@extends('layouts.app')

@section('content')
    <x-app.page
        :title="__('operations.accounting.close_readiness')"
        :subtitle="__('operations.accounting.close_readiness_help')"
        icon="check-circle"
    >
        <x-ui.card>
            <form method="GET" class="grid gap-4 md:grid-cols-3">
                <x-ui.input name="start_date" type="date" :value="$startDate" :label="__('operations.accounting.start_date')" required />
                <x-ui.input name="end_date" type="date" :value="$endDate" :label="__('operations.accounting.end_date')" required />
                <div class="flex items-end"><x-ui.button type="submit">{{ __('operations.accounting.run_close_checks') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <div class="mt-5 grid gap-4 sm:grid-cols-4">
            <x-ui.stat-card :title="__('operations.accounting.close_status')" :value="$assessment['ready'] ? __('operations.accounting.ready') : __('operations.accounting.not_ready')" icon="check-circle" :tone="$assessment['ready'] ? 'success' : 'danger'" />
            <x-ui.stat-card :title="__('operations.accounting.blockers')" :value="$assessment['blockers']" icon="exclamation-triangle" tone="danger" />
            <x-ui.stat-card :title="__('operations.accounting.warnings')" :value="$assessment['warnings']" icon="information-circle" tone="warning" />
            <x-ui.stat-card :title="__('operations.accounting.passed_checks')" :value="$assessment['passes']" icon="check-circle" tone="success" />
        </div>

        <x-ui.card class="mt-5">
            <div class="space-y-3">
                @foreach($assessment['checks'] as $check)
                    <div class="flex items-start justify-between gap-4 rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                        <div>
                            <p class="font-semibold text-slate-900 dark:text-white">{{ __('operations.accounting.close_check_'.$check['id']) }}</p>
                            <p class="mt-1 text-sm text-slate-500">{{ $check['message'] }}</p>
                        </div>
                        <x-ui.badge :tone="$check['severity'] === 'pass' ? 'success' : ($check['severity'] === 'blocker' ? 'danger' : 'warning')">
                            {{ __('operations.accounting.'.$check['severity']) }}
                        </x-ui.badge>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </x-app.page>
@endsection
