@props(['appName' => null])

<div class="flex h-16 shrink-0 items-center gap-3 border-b border-gray-200 px-4 dark:border-gray-700" x-bind:class="collapsed ? 'justify-center px-2' : 'justify-between'">
    <div class="flex min-w-0 items-center gap-3">
        <div class="grid size-8 shrink-0 place-items-center rounded-lg bg-brand-600 text-white shadow-sm" aria-hidden="true">
            <x-ui.icon name="sparkles" class="size-4" />
        </div>
        <div class="min-w-0 leading-tight">
            <p class="truncate text-sm font-semibold text-gray-900 dark:text-white" x-bind:class="collapsed ? 'sr-only' : ''">{{ $appName ?? __('common.app_name') }}</p>
            <p class="truncate text-xs text-gray-500 dark:text-gray-400" x-bind:class="collapsed ? 'sr-only' : ''">{{ __('common.brand_tagline') }}</p>
        </div>
    </div>

    @if ($slot->isNotEmpty())
        <div class="flex items-center gap-1">
            {{ $slot }}
        </div>
    @endif
</div>