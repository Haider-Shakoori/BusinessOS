@props([
    'caption' => null,
])

<div {{ $attributes->merge(['class' => 'overflow-hidden rounded-[8px] border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900']) }}>
    <div class="overflow-x-auto">
        <table class="min-w-full border-collapse">
            @if ($caption)
                <caption class="sr-only">{{ $caption }}</caption>
            @endif

            @if (! empty($head))
                <thead class="border-b border-slate-200 bg-slate-50/90 dark:border-slate-700 dark:bg-slate-800/70">{{ $head }}</thead>
            @endif

            @if ($slot->isNotEmpty())
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">{{ $slot }}</tbody>
            @endif
        </table>
    </div>

    @if (isset($footer))
        <div class="border-t border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
            {{ $footer }}
        </div>
    @endif
</div>
