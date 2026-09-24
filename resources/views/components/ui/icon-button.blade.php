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
        throw new \InvalidArgumentException('The icon-button component requires slot content (an icon) or the `icon` attribute.');
    }

    $variants = [
        'secondary' => 'text-gray-600 ring-gray-300 hover:bg-gray-50 focus-visible:outline-brand-600 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700',
        'ghost' => 'text-gray-500 ring-transparent hover:bg-gray-100 hover:text-gray-800 focus-visible:outline-brand-600 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100',
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

    $classes = 'inline-flex shrink-0 items-center justify-center rounded-lg ring-1 ring-inset
        transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2
        disabled:pointer-events-none disabled:opacity-50'
        .' '.($variants[$variant] ?? $variants['secondary'])
        .' '.($sizes[$size] ?? $sizes['md']);
@endphp

@if ($href)
    <a
        href="{{ $href }}"
        aria-label="{{ $label ?? 'Icon button' }}"
        {{ $attributes->merge(['class' => $classes]) }}
    >
        @if ($icon)
            <x-ui.icon :name="$icon" :class="$iconSizes[$size]" />
        @else
            <span class="{{ $iconSizes[$size] }} inline-flex items-center justify-center">
                {{ $slot }}
            </span>
        @endif
    </a>
@else
    <button
        type="{{ $type }}"
        aria-label="{{ $label ?? 'Icon button' }}"
        {{ $attributes->merge(['class' => $classes]) }}
    >
        @if ($icon)
            <x-ui.icon :name="$icon" :class="$iconSizes[$size]" />
        @else
            <span class="{{ $iconSizes[$size] }} inline-flex items-center justify-center">{{ $slot }}</span>
        @endif
    </button>
@endif