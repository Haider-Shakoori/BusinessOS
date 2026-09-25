@props([
    'href' => null,
    'variant' => 'default',
    'icon' => null,
    'disabled' => false,
])

@php
    $variants = [
        'default' => 'text-slate-700 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-white',
        'danger' => 'text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10',
    ];
    $classes = 'flex w-full items-center gap-2.5 rounded-[7px] px-3 py-2 text-[12px] font-semibold transition-colors duration-150 '
        .($variants[$variant] ?? $variants['default']).' '
        .($disabled ? 'pointer-events-none opacity-50' : '');
@endphp

@if ($href)
    <a href="{{ $href }}" role="menuitem" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)
            <x-ui.icon :name="$icon" class="size-4 text-slate-400 dark:text-slate-500" aria-hidden="true" />
        @endif
        {{ $slot }}
    </a>
@else
    <button type="button" role="menuitem" @if ($disabled) disabled @endif {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)
            <x-ui.icon :name="$icon" class="size-4 text-slate-400 dark:text-slate-500" aria-hidden="true" />
        @endif
        {{ $slot }}
    </button>
@endif