@props([
    'bare' => false,
])

<div {{ $attributes->merge(['class' => 'rounded-[10px] border border-slate-200/90 bg-white shadow-card dark:border-slate-700 dark:bg-slate-900']) }}>
    @if (! empty($header))
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
            <div class="min-w-0 text-[14px] font-semibold text-slate-900 dark:text-white">{{ $header }}</div>
            @if (! empty($actions))
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endif
        </div>
    @endif

    <div @if (! $bare) class="p-5" @endif>
        {{ $slot }}
    </div>

    @if (! empty($footer))
        <div class="border-t border-slate-200 bg-slate-50/70 px-5 py-3.5 dark:border-slate-700 dark:bg-slate-800/50">
            {{ $footer }}
        </div>
    @endif
</div>
