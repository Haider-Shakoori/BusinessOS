@props([
    'id' => null,
    'title' => null,
    'description' => null,
    'size' => 'md',
    'open' => false,
    'dismissible' => true,
])

@php
    $sizes = [
        'sm' => 'max-w-sm',
        'md' => 'max-w-lg',
        'lg' => 'max-w-2xl',
        'xl' => 'max-w-4xl',
    ];
@endphp

<div
    x-data="modalDialog(@js($open), @js($id))"
    x-cloak
    x-show="open"
    x-transition:enter="transition ease-out duration-150"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-100"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed inset-0 z-50"
    role="dialog"
    aria-modal="true"
    @if ($title) aria-labelledby="{{ $id ?? 'modal' }}-title" @endif
    @keydown.window.escape="close"
>
    <div class="absolute inset-0 flex min-h-full items-end justify-center p-4 sm:items-center sm:p-6">
        <div class="absolute inset-0 bg-gray-950/50 backdrop-blur-[2px]" x-on:click="dismissible && close()" aria-hidden="true"></div>

        <div
            x-ref="panel"
            tabindex="-1"
            x-show="open"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 translate-y-3 sm:translate-y-0 sm:scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
            x-transition:leave-end="opacity-0 translate-y-3 sm:translate-y-0 sm:scale-95"
            class="relative w-full overflow-hidden rounded-t-2xl bg-white shadow-overlay ring-1 ring-black/5 sm:rounded-2xl dark:bg-gray-800 dark:ring-white/10 {{ $sizes[$size] ?? $sizes['md'] }}"
        >
            @if ($title || $slot->isNotEmpty())
                <div class="flex items-start justify-between gap-4 border-b border-gray-200 px-6 py-4 dark:border-gray-700">
                    <div class="min-w-0">
                        @if ($title)
                            <h2 id="{{ $id ?? 'modal' }}-title" class="text-base font-semibold text-gray-900 dark:text-white">{{ $title }}</h2>
                        @endif
                        @if ($description)
                            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">{{ $description }}</p>
                        @endif
                    </div>
                    @if ($dismissible)
                        <button
                            type="button"
                            x-on:click="close"
                            class="shrink-0 rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700 dark:hover:text-gray-200"
                            aria-label="Close"
                        >
                            <x-ui.icon name="x-mark" class="size-5" aria-hidden="true" />
                        </button>
                    @endif
                </div>
            @endif

            <div class="max-h-[70vh] overflow-y-auto p-6">
                {{ $slot }}
            </div>

            @if (! empty($footer))
                <div class="flex flex-wrap items-center justify-end gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4 dark:border-gray-700 dark:bg-gray-800/60">
                    {{ $footer }}
                </div>
            @endif
        </div>
    </div>
</div>