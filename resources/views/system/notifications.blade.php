@extends('layouts.app')

@section('content')
<x-app.page icon="bell" :title="__('system.notifications.title')" :subtitle="__('system.notifications.subtitle')">
    <x-slot:actions>
        @can('notifications.manage')
            @if($unreadCount > 0)
            <form method="POST" action="{{ route('system.notifications.mark-all-read') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary">{{ __('system.notifications.mark_all') }}</x-ui.button>
            </form>
            @endif
        @endcan
    </x-slot:actions>

    @if (session('status')) <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div> @endif

    <div class="mb-4 flex items-center gap-2">
        <x-ui.badge tone="brand">{{ $unreadCount }} {{ __('system.notifications.unread') }}</x-ui.badge>
    </div>

    <div class="space-y-3">
        @forelse($notifications as $notification)
            <x-ui.card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <h3 class="font-semibold text-slate-900 dark:text-white">{{ $notification->title }}</h3>
                            @if($notification->read_at === null)<span class="size-2 rounded-full bg-brand-500"></span>@endif
                        </div>
                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $notification->message }}</p>
                        <p class="mt-2 text-xs text-slate-500">{{ $notification->created_at?->diffForHumans() }}</p>
                    </div>
                    <div class="flex gap-2">
                        @if($notification->action_url)
                            <x-ui.button href="{{ $notification->action_url }}" size="sm" variant="secondary">{{ __('actions.view') }}</x-ui.button>
                        @endif
                        @if($notification->read_at === null)
                            <form method="POST" action="{{ route('system.notifications.mark-read', $notification) }}">
                                @csrf
                                <x-ui.button type="submit" size="sm">{{ __('system.notifications.mark_read') }}</x-ui.button>
                            </form>
                        @endif
                    </div>
                </div>
            </x-ui.card>
        @empty
            <x-ui.card><p class="text-sm text-slate-500">{{ __('system.notifications.empty') }}</p></x-ui.card>
        @endforelse
    </div>

    <div class="mt-5"><x-ui.pagination :paginator="$notifications" /></div>
</x-app.page>
@endsection
