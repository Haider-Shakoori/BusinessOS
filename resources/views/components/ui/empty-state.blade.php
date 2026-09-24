@props([
    'title' => null,
    'description' => null,
    'icon' => 'inbox',
])

<div
    {{ $attributes->merge(['class' => 'flex flex-col items-center px-6 py-16 text-center']) }}
>
    <div class="grid size-14 place-items-center rounded-2xl bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500">
        <x-ui.icon :name="$icon" class="size-7" aria-hidden="true" />
    </div>

    <h3 class="mt-4 text-base font-semibold text-gray-900 dark:text-white">{{ $title }}</h3>

    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
    @endif

    @if (! empty($actions))
        <div class="mt-5 flex items-center gap-3">{{ $actions }}</div>
    @endif
</div>