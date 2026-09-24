@props([
    'type' => 'success',
    'title' => null,
    'message' => null,
    'duration' => 5000,
])

@php
    $tones = [
        'success' => ['wrap' => 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300', 'icon' => 'text-emerald-500 dark:text-emerald-400', 'iconName' => 'check-circle'],
        'warning' => ['wrap' => 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300', 'icon' => 'text-amber-500 dark:text-amber-400', 'iconName' => 'exclamation-triangle'],
        'danger' => ['wrap' => 'border-red-200 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300', 'icon' => 'text-red-500 dark:text-red-400', 'iconName' => 'x-circle'],
        'info' => ['wrap' => 'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300', 'icon' => 'text-sky-500 dark:text-sky-400', 'iconName' => 'info-circle'],
    ];
    $tone = $tones[$type] ?? $tones['success'];
@endphp

<div
    x-data="{ show: true }"
    x-init="duration > 0 && setTimeout(() => show = false, @js($duration))"
    x-show="show"
    x-cloak
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0 translate-x-2"
    x-transition:enter-end="opacity-100 translate-x-0"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    x-on:transitionend.window="!show && $el.remove()"
    role="status"
    {{ $attributes->merge(['class' => 'pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border p-4 text-sm shadow-overlay '.$tone['wrap']]) }}
>
    <x-ui.icon :name="$tone['iconName']" class="mt-0.5 size-5 shrink-0 {{ $tone['icon'] }}" aria-hidden="true" />

    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        @if ($message)
            <p @if ($title) class="mt-0.5" @endif>{{ $message }}</p>
        @endif
    </div>

    <button
        type="button"
        x-on:click="show = false"
        class="shrink-0 rounded p-1 transition-colors hover:bg-black/5 dark:hover:bg-white/10"
        aria-label="Dismiss"
    >
        <x-ui.icon name="x-mark" class="size-4" aria-hidden="true" />
    </button>
</div>