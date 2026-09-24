@props([
    'label' => null,
    'size' => 'md',
])

@php
    $sizes = [
        'sm' => 'size-4',
        'md' => 'size-5',
        'lg' => 'size-7',
        'xl' => 'size-10',
    ];
@endphp

<span
    x-data="{ show: false }"
    x-init="setTimeout(() => show = true, 100)"
    x-show="show"
    x-cloak
    aria-live="polite"
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400']) }}
>
    <x-ui.icon name="spinner" :class="'animate-spin '.($sizes[$size] ?? $sizes['md']).' text-brand-600 dark:text-brand-400'" aria-hidden="true" />
    @if ($label)
        <span>{{ $label }}</span>
    @endif
    {{ $slot }}
</span>