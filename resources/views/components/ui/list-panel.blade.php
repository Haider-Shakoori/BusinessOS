@props([
    'title' => null,
    'icon' => 'funnel',
])

<x-ui.card bare {{ $attributes }}>
    <div class="p-5">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
            <h2 class="flex items-center gap-2.5 text-[15px] font-semibold text-slate-900 dark:text-white">
                <x-ui.icon :name="$icon" class="size-5 text-brand-600 dark:text-brand-400" />
                {{ $title ?? __('common.filters') }}
            </h2>

            @if (! empty($actions))
                <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
            @endif
        </div>

        @if (! empty($filters))
            <div class="mt-5">{{ $filters }}</div>
        @endif
    </div>

    <div class="border-t border-slate-200 px-5 pb-5 pt-4 dark:border-slate-700">
        {{ $slot }}
    </div>
</x-ui.card>
