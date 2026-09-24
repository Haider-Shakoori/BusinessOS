@props([
    'title' => null,
    'description' => null,
    'icon' => null,
])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between']) }}>
    <div class="flex min-w-0 items-start gap-3">
        @if ($icon)
            <span class="mt-0.5 grid size-10 shrink-0 place-items-center text-brand-600 dark:text-brand-400" aria-hidden="true">
                <x-ui.icon :name="$icon" class="size-8" />
            </span>
        @endif
        <div class="min-w-0">
            <h1 class="text-[26px] font-bold leading-tight tracking-[-0.025em] text-slate-900 dark:text-white">{{ $title ?? $slot }}</h1>
            @if ($description)
                <p class="mt-1 text-[13px] leading-5 text-slate-600 dark:text-slate-400">{{ $description }}</p>
            @endif
        </div>
    </div>

    @if (! empty($actions))
        <div class="flex flex-wrap items-center gap-2 sm:ms-auto">{{ $actions }}</div>
    @endif
</div>
