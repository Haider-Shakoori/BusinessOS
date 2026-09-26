@extends('layouts.app')

@section('content')
<x-app.page icon="saas" :title="__('businesses.title')" :subtitle="__('businesses.subtitle')">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ trans_choice('businesses.count', $memberships->count(), ['count' => $memberships->count()]) }}</p>
        <div class="flex flex-wrap gap-2">
            @if(auth()->user()?->is_super_admin)
                <x-ui.button :href="route('platform.saas.index')" variant="secondary" icon="saas">{{ __('saas.platform_console') }}</x-ui.button>
            @endif
            @can('businesses.manage')
                <x-ui.button :href="route('business.create')" icon="plus">{{ __('businesses.create') }}</x-ui.button>
            @endcan
        </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
        @foreach($memberships as $membership)
            @php
                $business = $membership->business;
                $enabledModules = $business?->modules?->where('enabled', true)->count() ?? 0;
                $isCurrent = $business?->id === $currentBusinessId;
            @endphp
            <x-ui.card>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ $business?->name }}</h2>
                            @if($isCurrent)<x-ui.badge tone="success">{{ __('businesses.current') }}</x-ui.badge>@endif
                        </div>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            {{ __('businesses.roles') }}: {{ $membership->roles->pluck('name')->join(', ') ?: '—' }}
                        </p>
                    </div>
                    @unless($isCurrent)
                        <form method="POST" action="{{ route('business.switch') }}">
                            @csrf
                            <input type="hidden" name="business_id" value="{{ $business?->id }}">
                            <x-ui.button type="submit" variant="secondary" size="sm">{{ __('businesses.switch') }}</x-ui.button>
                        </form>
                    @endunless
                </div>

                <div class="mt-5 grid grid-cols-2 gap-3">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">{{ __('businesses.enabled_modules') }}</p>
                        <p class="mt-1 text-xl font-semibold text-slate-900 dark:text-white">{{ $enabledModules }}</p>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/50">
                        <p class="text-[11px] uppercase tracking-wide text-slate-500">{{ __('businesses.business_id') }}</p>
                        <p class="mt-1 text-xl font-semibold text-slate-900 dark:text-white">#{{ $business?->id }}</p>
                    </div>
                </div>

                @if($isCurrent)
                    <div class="mt-4 flex flex-wrap gap-2">
                        @can('modules.view')
                            <x-ui.button :href="route('system.modules.index')" variant="secondary" size="sm" icon="module-grid">{{ __('businesses.manage_modules') }}</x-ui.button>
                        @endcan
                        @can('users.view')
                            <x-ui.button :href="route('system.users-roles.index')" variant="secondary" size="sm" icon="user-group">{{ __('businesses.manage_users') }}</x-ui.button>
                        @endcan
                        @can('settings.view')
                            <x-ui.button :href="route('settings.index')" variant="secondary" size="sm" icon="cog">{{ __('businesses.settings') }}</x-ui.button>
                        @endcan
                    </div>
                @endif
            </x-ui.card>
        @endforeach
    </div>
</x-app.page>
@endsection
