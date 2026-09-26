@extends('layouts.app')

@section('content')
<x-app.page icon="user-group" :title="__('system.users.title')" :subtitle="__('system.users.subtitle')">
    @if (session('status')) <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div> @endif
    @if ($errors->any()) <div class="mb-5"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div> @endif

    @can('users.manage')
    <div class="grid gap-5 xl:grid-cols-2">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('system.users.add_member') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('system.users-roles.members.store') }}" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-ui.input name="name" :label="__('system.users.name')" required />
                <x-ui.input name="email" type="email" :label="__('system.users.email')" required />
                <div>
                    <x-ui.input name="password" type="password" :label="__('system.users.password')" />
                    <p class="mt-1 text-xs text-slate-500">{{ __('system.users.password_hint') }}</p>
                </div>
                <x-ui.select name="role_id" :label="__('system.users.role')" required>
                    @foreach($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach
                </x-ui.select>
                <div class="sm:col-span-2 flex justify-end"><x-ui.button type="submit" icon="plus">{{ __('system.users.add_member') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('system.roles.create') }}</h2></x-slot:header>
            <form method="POST" action="{{ route('system.users-roles.roles.store') }}" class="space-y-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-ui.input name="name" :label="__('system.roles.name')" required />
                    <x-ui.input name="slug" :label="__('system.roles.slug')" required />
                </div>
                <x-ui.input name="description" :label="__('system.roles.description')" />
                <div>
                    <p class="mb-2 text-sm font-medium text-slate-700 dark:text-slate-300">{{ __('system.roles.permissions') }}</p>
                    <div class="max-h-56 space-y-3 overflow-y-auto rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        @foreach($permissions as $group => $groupPermissions)
                            <div>
                                <p class="mb-1 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ ucfirst($group) }}</p>
                                <div class="grid gap-2 sm:grid-cols-2">
                                    @foreach($groupPermissions as $permission)
                                        <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300">
                                            <input type="checkbox" name="permissions[]" value="{{ $permission->name }}" class="rounded border-slate-300 text-brand-600">
                                            <span>{{ $permission->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="flex justify-end"><x-ui.button type="submit" icon="plus">{{ __('system.roles.create') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>
    @endcan

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('system.users.members') }}</h2></x-slot:header>
            <div class="space-y-3">
                @forelse($memberships as $membership)
                    <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="font-medium text-slate-900 dark:text-white">{{ $membership->user?->name }}</div>
                                <div class="text-xs text-slate-500">{{ $membership->user?->email }}</div>
                            </div>
                            @can('users.manage')
                                <form method="POST" action="{{ route('system.users-roles.members.update', $membership) }}" class="flex items-center gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <select name="role_id" class="rounded-md border-slate-300 text-xs dark:border-slate-700 dark:bg-slate-900">
                                        @foreach($roles as $role)
                                            <option value="{{ $role->id }}" @selected($membership->roles->contains('id', $role->id))>{{ $role->name }}</option>
                                        @endforeach
                                    </select>
                                    <x-ui.button type="submit" size="sm" variant="secondary">{{ __('actions.save') }}</x-ui.button>
                                </form>
                            @else
                                <div class="text-xs text-slate-500">{{ $membership->roles->pluck('name')->join(', ') }}</div>
                            @endcan
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-slate-500">{{ __('system.users.no_members') }}</p>
                @endforelse
            </div>
        </x-ui.card>

        <x-ui.card>
            <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('system.roles.title') }}</h2></x-slot:header>
            <div class="space-y-4">
                @foreach($roles as $role)
                    <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <div class="flex items-center gap-2">
                                    <h3 class="font-semibold text-slate-900 dark:text-white">{{ $role->name }}</h3>
                                    <x-ui.badge :tone="$role->is_system ? 'brand' : 'neutral'">{{ $role->is_system ? __('system.roles.system') : __('system.roles.custom') }}</x-ui.badge>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">{{ $role->slug }}</p>
                            </div>
                            <span class="text-xs text-slate-500">{{ $role->permissions->count() }} {{ __('system.roles.permissions') }}</span>
                        </div>

                        @if($role->description)<p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $role->description }}</p>@endif

                        @if($role->is_system)
                            <div class="mt-3 flex flex-wrap gap-1.5">
                                @foreach($role->permissions->take(12) as $permission)<x-ui.badge tone="neutral">{{ $permission->name }}</x-ui.badge>@endforeach
                                @if($role->permissions->count() > 12)<x-ui.badge tone="neutral">+{{ $role->permissions->count() - 12 }}</x-ui.badge>@endif
                            </div>
                        @can('users.manage')
                        @else
                        @endcan
                        @else
                            @can('users.manage')
                            <form method="POST" action="{{ route('system.users-roles.roles.update', $role) }}" class="mt-4 space-y-3">
                                @csrf
                                @method('PATCH')
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <x-ui.input name="name" :label="__('system.roles.name')" :value="$role->name" required />
                                    <x-ui.input name="description" :label="__('system.roles.description')" :value="$role->description" />
                                </div>
                                <div class="grid max-h-48 gap-2 overflow-y-auto rounded-lg border border-slate-200 p-3 sm:grid-cols-2 dark:border-slate-700">
                                    @foreach($permissions->flatten() as $permission)
                                        <label class="flex items-center gap-2 text-xs text-slate-700 dark:text-slate-300">
                                            <input type="checkbox" name="permissions[]" value="{{ $permission->name }}" @checked($role->permissions->contains('id', $permission->id)) class="rounded border-slate-300 text-brand-600">
                                            <span>{{ $permission->name }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <div class="flex justify-end"><x-ui.button type="submit" size="sm">{{ __('system.roles.save') }}</x-ui.button></div>
                            </form>
                            @endcan
                        @endif
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>
</x-app.page>
@endsection
