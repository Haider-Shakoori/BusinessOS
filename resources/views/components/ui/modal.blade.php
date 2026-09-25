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
    <div class="absolute inset-0 flex min-h-full items-end justify-center p-3 sm:items-center sm:p-6">
        <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-[2px]" x-on:click="dismissible && close()" aria-hidden="true"></div>

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
            class="relative w-full overflow-hidden rounded-t-[12px] border border-slate-200 bg-white shadow-overlay sm:rounded-[12px] dark:border-slate-700 dark:bg-slate-900 {{ $sizes[$size] ?? $sizes['md'] }}"
        >
            @if ($title || $description)
                <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                    <div class="min-w-0">
                        @if ($title)
                            <h2 id="{{ $id ?? 'modal' }}-title" class="text-[15px] font-semibold text-slate-900 dark:text-white">{{ $title }}</h2>
                        @endif
                        @if ($description)
                            <p class="mt-1 text-[12px] leading-5 text-slate-500 dark:text-slate-400">{{ $description }}</p>
                        @endif
                    </div>
                    @if ($dismissible)
                        <button
                            type="button"
                            x-on:click="close"
                            class="shrink-0 rounded-[7px] p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                            aria-label="{{ __('actions.close') }}"
                        >
                            <x-ui.icon name="x-mark" class="size-5" aria-hidden="true" />
                        </button>
                    @endif
                </div>
            @endif

            <div class="bos-scrollbar max-h-[72vh] overflow-y-auto p-5">
                {{ $slot }}
            </div>

            @if (! empty($footer))
                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-slate-50/70 px-5 py-3.5 dark:border-slate-700 dark:bg-slate-800/50">
                    {{ $footer }}
                </div>
            @endif
        </div>
    </div>
</div>
