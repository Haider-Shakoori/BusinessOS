@props([
    'title' => null,
    'value' => null,
    'hint' => null,
    'icon' => null,
    'tone' => 'brand',
    'trend' => null,
    'trendUp' => null,
    'valueClass' => null,
])

@php
    $tones = [
        'brand' => 'bg-gradient-to-br from-brand-500 to-brand-600 text-white shadow-[0_5px_16px_rgba(20,115,230,0.22)]',
        'success' => 'bg-gradient-to-br from-emerald-400 to-emerald-600 text-white shadow-[0_5px_16px_rgba(5,150,105,0.18)]',
        'warning' => 'bg-gradient-to-br from-amber-400 to-amber-500 text-white',
        'danger' => 'bg-gradient-to-br from-violet-400 to-violet-600 text-white shadow-[0_5px_16px_rgba(124,58,237,0.16)]',
        'info' => 'bg-gradient-to-br from-sky-400 to-sky-600 text-white',
        'neutral' => 'bg-gradient-to-br from-slate-500 to-slate-600 text-white',
    ];
    $trendUp ??= true;
@endphp

<div {{ $attributes->merge(['class' => 'rounded-[9px] border border-slate-200/90 bg-white px-4 py-4 shadow-card dark:border-slate-700 dark:bg-slate-900']) }}>
    <div class="flex items-center gap-4">
        @if ($icon)
            <div class="grid size-[52px] shrink-0 place-items-center rounded-[11px] {{ $tones[$tone] ?? $tones['brand'] }}">
                <x-ui.icon :name="$icon" class="size-6" />
            </div>
        @endif

        <div class="min-w-0">
            <p class="truncate text-[12px] font-medium text-slate-500 dark:text-slate-400">{{ $title }}</p>
            <p class="mt-1 truncate text-[19px] font-bold leading-tight tracking-[-0.02em] {{ $valueClass ?: 'text-slate-900 dark:text-white' }}">{{ $value }}</p>
            @if ($hint)
                <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{{ $hint }}</p>
            @endif
            @if ($trend)
                <div class="mt-1 inline-flex items-center gap-1 text-[11px] font-medium">
                    <x-ui.icon
                        :name="$trendUp ? 'arrow-trending-up' : 'arrow-trending-down'"
                        class="size-3 {{ $trendUp ? 'text-emerald-500' : 'text-red-500' }}"
                    />
                    <span class="{{ $trendUp ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">{{ $trend }}</span>
                </div>
            @endif
        </div>
    </div>
</div>
