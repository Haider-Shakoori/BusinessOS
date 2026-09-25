@props([
    'tone' => 'neutral',
    'dot' => false,
    'size' => 'md',
])

@php
    $tones = [
        'neutral' => 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200',
        'brand' => 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300',
        'success' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
        'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
        'danger' => 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
        'info' => 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
    ];
    $dots = [
        'neutral' => 'bg-slate-400 dark:bg-slate-500',
        'brand' => 'bg-brand-500 dark:bg-brand-400',
        'success' => 'bg-emerald-500 dark:bg-emerald-400',
        'warning' => 'bg-amber-500 dark:bg-amber-400',
        'danger' => 'bg-red-500 dark:bg-red-400',
        'info' => 'bg-sky-500 dark:bg-sky-400',
    ];
    $sizes = [
        'sm' => 'px-2 py-0.5 text-[10px]',
        'md' => 'px-2.5 py-1 text-[11px]',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full font-medium '.($sizes[$size] ?? $sizes['md']).' '.($tones[$tone] ?? $tones['neutral'])]) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full {{ $dots[$tone] ?? $dots['neutral'] }}" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
