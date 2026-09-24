@props([
    'caption' => null,
])

<div
    {{ $attributes->merge(['class' => 'overflow-hidden rounded-xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800']) }}
>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            @if ($caption)
                <caption class="sr-only">{{ $caption }}</caption>
            @endif

            @if (! empty($head))
                <thead class="bg-gray-50 dark:bg-gray-800/80">{{ $head }}</thead>
            @endif

            @if ($slot->isNotEmpty())
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">{{ $slot }}</tbody>
            @endif
        </table>
    </div>

    @if (isset($footer))
        {{ $footer }}
    @endif
</div>