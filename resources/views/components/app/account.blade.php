@props([
    'name' => null,
    'role' => null,
    'initials' => null,
])

@php
    $name = $name ?? auth()->user()?->name ?? __('auth.guest');
    $role = $role ?? auth()->user()?->email ?? __('auth.preview');
    $initials = $initials ?? mb_strtoupper(mb_substr($name, 0, 1));
@endphp

<div class="flex min-w-0 items-center gap-3">
    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-600 text-xs font-semibold text-white shadow-sm" aria-hidden="true">{{ $initials }}</span>
    <span class="min-w-0 leading-tight">
        <span class="block truncate text-sm font-medium text-slate-700 dark:text-slate-200" x-bind:class="collapsed ? 'sr-only' : ''">{{ $name }}</span>
        <span class="block truncate text-xs text-slate-500 dark:text-slate-400" x-bind:class="collapsed ? 'sr-only' : ''">{{ $role }}</span>
    </span>
</div>
