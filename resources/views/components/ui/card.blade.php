@props([
    'bare' => false,
])

<div
    {{ $attributes->merge(['class' => 'rounded-xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800']) }}
>
    @if (! empty($header))
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 px-6 py-4 dark:border-gray-700">
            <div class="min-w-0">
                {{ $header }}
            </div>
            @if (! empty($actions))
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endif
        </div>
    @endif

    <div @if (! $bare) class="p-6" @endif>
        {{ $slot }}
    </div>

    @if (! empty($footer))
        <div class="border-t border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-800/60">
            {{ $footer }}
        </div>
    @endif
</div>