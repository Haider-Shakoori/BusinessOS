@props([
    'type' => 'info',
    'title' => null,
    'dismissible' => false,
])

@php
    $tones = [
        'success' => [
            'wrap' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300',
            'icon' => 'text-emerald-500 dark:text-emerald-400',
            'iconName' => 'check-circle',
        ],
        'warning' => [
            'wrap' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300',
            'icon' => 'text-amber-500 dark:text-amber-400',
            'iconName' => 'exclamation-triangle',
        ],
        'danger' => [
            'wrap' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300',
            'icon' => 'text-red-500 dark:text-red-400',
            'iconName' => 'x-circle',
        ],
        'info' => [
            'wrap' => 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-300',
            'icon' => 'text-brand-600 dark:text-brand-400',
            'iconName' => 'info-circle',
        ],
        'neutral' => [
            'wrap' => 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200',
            'icon' => 'text-slate-400 dark:text-slate-500',
            'iconName' => 'info-circle',
        ],
    ];
    $tone = $tones[$type] ?? $tones['info'];
@endphp

<div
    x-data="{ show: true }"
    x-show="show"
    x-cloak
    x-transition:enter="transition ease-out duration-150"
    x-transition:enter-start="opacity-0 -translate-y-1"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-100"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    role="{{ $type === 'danger' ? 'alert' : 'status' }}"
    {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-[9px] border px-4 py-3 text-[12px] leading-5 '.$tone['wrap']]) }}
>
    <x-ui.icon :name="$tone['iconName']" class="mt-0.5 size-4.5 shrink-0 {{ $tone['icon'] }}" aria-hidden="true" />

    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        @if ($slot->isNotEmpty())
            <div @if ($title) class="mt-0.5" @endif>{{ $slot }}</div>
        @endif
    </div>

    @if ($dismissible)
        <button
            type="button"
            x-on:click="show = false"
            class="shrink-0 rounded p-1 transition-colors hover:bg-black/5 dark:hover:bg-white/10"
            aria-label="{{ __('actions.close') }}"
        >
            <x-ui.icon name="x-mark" class="size-4" aria-hidden="true" />
        </button>
    @endif
</div>
