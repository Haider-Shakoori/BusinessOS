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
        'primary' => 'border-transparent bg-brand-600 text-white shadow-sm hover:bg-brand-700 focus-visible:outline-brand-600 dark:bg-brand-600 dark:hover:bg-brand-500',
        'secondary' => 'border-gray-300 bg-white text-gray-700 shadow-sm hover:bg-gray-50 focus-visible:outline-brand-600 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700',
        'outline' => 'border-brand-300 bg-transparent text-brand-700 hover:bg-brand-50 focus-visible:outline-brand-600 dark:border-brand-700 dark:text-brand-300 dark:hover:bg-brand-500/10',
        'ghost' => 'border-transparent bg-transparent text-gray-700 hover:bg-gray-100 focus-visible:outline-brand-600 dark:text-gray-200 dark:hover:bg-gray-800',
        'danger' => 'border-transparent bg-red-600 text-white shadow-sm hover:bg-red-700 focus-visible:outline-red-600 dark:bg-red-600 dark:hover:bg-red-500',
    ];

    $sizes = [
        'sm' => 'gap-1.5 px-2.5 py-1.5 text-xs',
        'md' => 'gap-2 px-3.5 py-2 text-sm',
        'lg' => 'gap-2 px-5 py-2.5 text-base',
    ];

    $iconSizes = [
        'sm' => 'size-3.5',
        'md' => 'size-4',
        'lg' => 'size-5',
    ];

    $classes = 'inline-flex shrink-0 items-center justify-center rounded-lg border font-semibold
        transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2
        disabled:pointer-events-none disabled:opacity-60'
        .' '.($variants[$variant] ?? $variants['primary'])
        .' '.($sizes[$size] ?? $sizes['md']);
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        @if ($loading) aria-disabled="true" @endif
        {{ $attributes->merge(['class' => $classes]) }}
    >
        @if ($loading)
            <x-ui.icon name="spinner" :class="'animate-spin '.$iconSizes[$size]" />
        @elseif ($icon)
            <x-ui.icon :name="$icon" :class="$iconSizes[$size]" />
        @endif
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        @if ($loading) disabled @endif
        {{ $attributes->merge(['class' => $classes]) }}
    >
        @if ($loading)
            <x-ui.icon name="spinner" :class="'animate-spin '.$iconSizes[$size]" />
        @elseif ($icon)
            <x-ui.icon :name="$icon" :class="$iconSizes[$size]" />
        @endif
        {{ $slot }}
    </button>
@endif