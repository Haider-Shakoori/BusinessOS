@props([
    'title' => null,
    'description' => null,
    'icon' => 'inbox',
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center px-6 py-14 text-center']) }}>
    <div class="grid size-12 place-items-center rounded-[10px] bg-brand-50 text-brand-600 ring-1 ring-inset ring-brand-100 dark:bg-brand-500/10 dark:text-brand-400 dark:ring-brand-500/20">
        <x-ui.icon :name="$icon" class="size-6" aria-hidden="true" />
    </div>

    <h3 class="mt-4 text-[14px] font-semibold text-slate-900 dark:text-white">{{ $title }}</h3>

    @if ($description)
        <p class="mt-1.5 max-w-md text-[12px] leading-5 text-slate-500 dark:text-slate-400">{{ $description }}</p>
    @endif

    @if (! empty($actions))
        <div class="mt-5 flex flex-wrap items-center justify-center gap-2">{{ $actions }}</div>
    @endif
</div>
