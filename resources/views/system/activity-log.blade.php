@extends('layouts.app')

@section('content')
<x-app.page icon="history" :title="__('system.activity.title')" :subtitle="__('system.activity.subtitle')">
    <x-ui.card>
        <form method="GET" action="{{ route('system.activity.index') }}" class="grid gap-3 md:grid-cols-4">
            <x-ui.search-input name="search" :label="__('system.activity.search')" :value="$searchTerm" />
            <x-ui.select name="event" :label="__('system.activity.event')">
                <option value="">{{ __('system.activity.all_events') }}</option>
                @foreach($events as $event)<option value="{{ $event }}" @selected($eventFilter === $event)>{{ ucfirst(str_replace('_', ' ', $event)) }}</option>@endforeach
            </x-ui.select>
            <x-ui.select name="user_id" :label="__('system.activity.user')">
                <option value="">{{ __('system.activity.all_users') }}</option>
                @foreach($users as $user)<option value="{{ $user->id }}" @selected($userFilter === $user->id)>{{ $user->name }}</option>@endforeach
            </x-ui.select>
            <div class="flex items-end gap-2">
                <x-ui.button type="submit" icon="search">{{ __('actions.search') }}</x-ui.button>
                <x-ui.button href="{{ route('system.activity.index') }}" variant="secondary">{{ __('actions.clear') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <div class="mt-5">
        <x-ui.card>
            <div class="overflow-x-auto">
                <x-ui.table>
                    <x-slot:head><tr>
                        <x-ui.th>{{ __('system.activity.date') }}</x-ui.th>
                        <x-ui.th>{{ __('system.activity.user') }}</x-ui.th>
                        <x-ui.th>{{ __('system.activity.event') }}</x-ui.th>
                        <x-ui.th>{{ __('system.activity.route') }}</x-ui.th>
                        <x-ui.th>{{ __('system.activity.method') }}</x-ui.th>
                        <x-ui.th>{{ __('system.activity.status') }}</x-ui.th>
                        <x-ui.th>{{ __('system.activity.ip') }}</x-ui.th>
                    </tr></x-slot:head>
                    @forelse($logs as $log)
                        <tr>
                            <x-ui.td><span class="whitespace-nowrap">{{ $log->occurred_at?->format('Y-m-d H:i:s') }}</span></x-ui.td>
                            <x-ui.td>{{ $log->user?->name ?: '—' }}</x-ui.td>
                            <x-ui.td><x-ui.badge tone="neutral">{{ ucfirst(str_replace('_', ' ', $log->event)) }}</x-ui.badge></x-ui.td>
                            <x-ui.td><span class="font-mono text-xs">{{ $log->route_name ?: $log->path }}</span></x-ui.td>
                            <x-ui.td>{{ $log->method }}</x-ui.td>
                            <x-ui.td>{{ $log->status_code }}</x-ui.td>
                            <x-ui.td><span class="font-mono text-xs">{{ $log->ip_address }}</span></x-ui.td>
                        </tr>
                    @empty
                        <tr><x-ui.td colspan="7">{{ __('system.activity.no_logs') }}</x-ui.td></tr>
                    @endforelse
                    <x-slot:footer><div class="px-4 py-3"><x-ui.pagination :paginator="$logs" /></div></x-slot:footer>
                </x-ui.table>
            </div>
        </x-ui.card>
    </div>
</x-app.page>
@endsection
