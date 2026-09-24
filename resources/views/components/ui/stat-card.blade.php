@props([
    'title' => null,
    'value' => null,
    'hint' => null,
    'icon' => null,
    'tone' => 'brand',
    'trend' => null,
    'trendUp' => null,
])

@php
    $tones = [
        'brand' => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
        'success' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-400',
        'warning' => 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-400',
        'danger' => 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-400',
        'info' => 'bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400',
        'neutral' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    ];
    $trendUp ??= true;
@endphp

<div
    {{ $attributes->merge(['class' => 'rounded-xl border border-gray-200 bg-white p-6 shadow-card dark:border-gray-700 dark:bg-gray-800']) }}
>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="truncate text-sm font-medium text-gray-500 dark:text-gray-400">{{ $title }}</p>
            <p class="mt-2 text-2xl font-bold tracking-tight text-gray-900 dark:text-white">{{ $value }}</p>
            @if ($hint)
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
            @endif
        </div>

        @if ($icon)
            <div class="grid size-11 shrink-0 place-items-center rounded-lg {{ $tones[$tone] ?? $tones['brand'] }}">
                <x-ui.icon :name="$icon" class="size-5" />
            </div>
        @endif
    </div>

    @if ($trend)
        <div class="mt-4 inline-flex items-center gap-1.5 text-xs font-medium">
            <x-ui.icon
                :name="$trendUp ? 'arrow-trending-up' : 'arrow-trending-down'"
                class="size-3.5 {{ $trendUp ? 'text-emerald-500 dark:text-emerald-400' : 'text-red-500 dark:text-red-400' }}"
            />
            <span class="{{ $trendUp ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">{{ $trend }}</span>
        </div>
    @endif
</div>