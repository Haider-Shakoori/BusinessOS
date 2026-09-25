@props([
    'type' => 'button',
    'variant' => 'secondary',
    'size' => 'md',
    'icon' => null,
    'label' => null,
    'href' => null,
])

@php
    if (! $icon && ! $slot->isNotEmpty()) {
        throw new \InvalidArgumentException('The icon-button component requires an icon attribute or slot content.');
    }

    $variants = [
        'secondary' => 'text-slate-600 ring-slate-200 hover:bg-slate-50 hover:text-slate-900 focus-visible:outline-brand-600 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800 dark:hover:text-white',
        'ghost' => 'text-slate-500 ring-transparent hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-brand-600 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white',
        'danger' => 'text-red-600 ring-red-200 hover:bg-red-50 focus-visible:outline-red-600 dark:text-red-400 dark:ring-red-900/40 dark:hover:bg-red-500/10',
    ];

    $sizes = [
        'sm' => 'size-8',
        'md' => 'size-9',
        'lg' => 'size-10',
    ];

    $iconSizes = [
        'sm' => 'size-3.5',
        'md' => 'size-4',
        'lg' => 'size-5',
    ];

    $classes = 'inline-flex shrink-0 items-center justify-center rounded-[7px] ring-1 ring-inset
        transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2
        disabled:pointer-events-none disabled:opacity-50'
        .' '.($variants[$variant] ?? $variants['secondary'])
        .' '.($sizes[$size] ?? $sizes['md']);
@endphp

@if ($href)
    <a href="{{ $href }}" aria-label="{{ $label ?? 'Icon button' }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)
            <x-ui.icon :name="$icon" :class="$iconSizes[$size]" />
        @else
            <span class="{{ $iconSizes[$size] }} inline-flex items-center justify-center">{{ $slot }}</span>
        @endif
    </a>
@else
    <button type="{{ $type }}" aria-label="{{ $label ?? 'Icon button' }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)
            <x-ui.icon :name="$icon" :class="$iconSizes[$size]" />
        @else
            <span class="{{ $iconSizes[$size] }} inline-flex items-center justify-center">{{ $slot }}</span>
        @endif
    </button>
@endif
