@props([
    'type' => 'button',
    'variant' => 'primary',
    'size' => 'md',
    'href' => null,
    'loading' => false,
    'icon' => null,
])

@php
    $variants = [
        'primary' => 'border-brand-600 bg-brand-600 text-white shadow-sm hover:border-brand-700 hover:bg-brand-700 focus-visible:outline-brand-600 dark:border-brand-500 dark:bg-brand-600 dark:hover:bg-brand-500',
        'secondary' => 'border-slate-300 bg-white text-slate-700 shadow-sm hover:border-slate-400 hover:bg-slate-50 focus-visible:outline-brand-600 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800',
        'outline' => 'border-brand-400 bg-white text-brand-700 hover:bg-brand-50 focus-visible:outline-brand-600 dark:border-brand-600 dark:bg-transparent dark:text-brand-300 dark:hover:bg-brand-500/10',
        'ghost' => 'border-transparent bg-transparent text-slate-700 hover:bg-slate-100 focus-visible:outline-brand-600 dark:text-slate-200 dark:hover:bg-slate-800',
        'danger' => 'border-red-600 bg-red-600 text-white shadow-sm hover:border-red-700 hover:bg-red-700 focus-visible:outline-red-600 dark:border-red-500 dark:bg-red-600 dark:hover:bg-red-500',
    ];

    $sizes = [
        'sm' => 'gap-1.5 px-2.5 py-1.5 text-xs',
        'md' => 'gap-2 px-3.5 py-[8px] text-[13px]',
        'lg' => 'gap-2 px-5 py-2.5 text-sm',
    ];

    $iconSizes = [
        'sm' => 'size-3.5',
        'md' => 'size-4',
        'lg' => 'size-5',
    ];

    $classes = 'inline-flex shrink-0 items-center justify-center rounded-[7px] border font-semibold
        transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2
        disabled:pointer-events-none disabled:opacity-60'
        .' '.($variants[$variant] ?? $variants['primary'])
        .' '.($sizes[$size] ?? $sizes['md']);
@endphp

@if ($href)
    <a href="{{ $href }}" @if ($loading) aria-disabled="true" @endif {{ $attributes->merge(['class' => $classes]) }}>
        @if ($loading)
            <x-ui.icon name="spinner" :class="'animate-spin '.$iconSizes[$size]" />
        @elseif ($icon)
            <x-ui.icon :name="$icon" :class="$iconSizes[$size]" />
        @endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" @if ($loading) disabled @endif {{ $attributes->merge(['class' => $classes]) }}>
        @if ($loading)
            <x-ui.icon name="spinner" :class="'animate-spin '.$iconSizes[$size]" />
        @elseif ($icon)
            <x-ui.icon :name="$icon" :class="$iconSizes[$size]" />
        @endif
        {{ $slot }}
    </button>
@endif
