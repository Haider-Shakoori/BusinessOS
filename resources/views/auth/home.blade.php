@extends('layouts.app')
@section('content')
    <x-app.page icon="chart-bar" :title="__('auth.home')">
        @if (session('status'))
            <x-ui.alert type="success" class="mb-6">{{ session('status') }}</x-ui.alert>
        @endif

        @php
            $context = app(\App\Services\BusinessContext::class);
            $current = $context->current();
            $membership = $context->membership();
            $roles = collect();
            if ($current) {
                $roles = $membership?->roles()->get() ?? collect();
            }
        @endphp

        <x-ui.card>
            <p class="text-lg font-medium">{{ __('auth.welcome', ['name' => auth()->user()->name]) }}</p>
            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ __('auth.signed_in') }}</p>

            @if ($current)
                <span class="mt-4 inline-flex items-center gap-2 rounded-full bg-brand-50 px-3 py-1 text-xs font-medium text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
                    <x-ui.icon name="briefcase" class="size-3.5" aria-hidden="true" />
                    {{ __('business.current_business') }}: {{ $current->name }}
                </span>

                <div class="mt-6 border-t border-slate-200 pt-5 dark:border-slate-700">
                    <h3 class="flex items-center gap-1.5 text-sm font-semibold text-slate-900 dark:text-slate-100">
                        <x-ui.icon name="shield-check" class="size-4" aria-hidden="true" />
                        {{ __('authorization.your_roles', ['business' => $current->name]) }}
                    </h3>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @forelse ($roles as $role)
                            @php
                                $roleKey = 'authorization.role_'.$role->slug;
                                $roleLabel = \Illuminate\Support\Facades\Lang::has($roleKey) ? __($roleKey) : $role->name;
                            @endphp
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-700 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200">
                                <x-ui.icon name="shield-check" class="size-3.5" aria-hidden="true" />
                                {{ $roleLabel }}
                            </span>
                        @empty
                            <span class="text-sm text-slate-500 dark:text-slate-400">{{ __('authorization.no_roles') }}</span>
                        @endforelse
                    </div>

                    <h3 class="mt-5 text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('authorization.your_permissions', ['business' => $current->name]) }}</h3>
                    <ul class="mt-2 grid gap-1.5 sm:grid-cols-2">
                        @can('users.view')
                            <li class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"><x-ui.icon name="check-circle" class="size-4 text-brand-600 dark:text-brand-400" aria-hidden="true" />{{ __('authorization.permission_users_view') }}</li>
                        @endcan
                        @can('users.manage')
                            <li class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"><x-ui.icon name="check-circle" class="size-4 text-brand-600 dark:text-brand-400" aria-hidden="true" />{{ __('authorization.permission_users_manage') }}</li>
                        @endcan
                        @can('settings.view')
                            <li class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"><x-ui.icon name="check-circle" class="size-4 text-brand-600 dark:text-brand-400" aria-hidden="true" />{{ __('authorization.permission_settings_view') }}</li>
                        @endcan
                        @can('settings.manage')
                            <li class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"><x-ui.icon name="check-circle" class="size-4 text-brand-600 dark:text-brand-400" aria-hidden="true" />{{ __('authorization.permission_settings_manage') }}</li>
                        @endcan
                        @can('customers.view')
                            <li class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300"><x-ui.icon name="check-circle" class="size-4 text-brand-600 dark:text-brand-400" aria-hidden="true" />{{ __('authorization.permission_customers_view') }}</li>
                        @endcan
                        @cannot('users.view')
                            @cannot('settings.view')
                                <li class="text-sm text-slate-500 dark:text-slate-400">{{ __('authorization.no_permissions') }}</li>
                            @endcannot
                        @endcannot
                    </ul>
                </div>
            @endif
        </x-ui.card>
    </x-app.page>
@endsection