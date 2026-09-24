@props([
    'title' => null,
    'description' => null,
])

<div
    {{ $attributes->merge(['class' => 'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between']) }}
>
    <div class="min-w-0">
        <h1 class="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl dark:text-white">{{ $title ?? $slot }}</h1>
        @if ($description)
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
        @endif
    </div>

    @if (! empty($actions))
        <div class="flex flex-wrap items-center gap-3 sm:ms-auto">{{ $actions }}</div>
    @endif
</div>