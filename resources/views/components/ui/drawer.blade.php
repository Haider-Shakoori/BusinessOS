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
        'md' => 'max-w-md',
        'lg' => 'max-w-lg',
        'xl' => 'max-w-2xl',
        'full' => 'max-w-full',
    ];
@endphp

<div
    x-data="drawerPanel(@js($open), @js($id))"
    x-cloak
    x-show="open"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed inset-0 z-50"
    role="dialog"
    aria-modal="true"
    @if ($title) aria-labelledby="{{ $id ?? 'drawer' }}-title" @endif
    @keydown.window.escape="close"
>
    <div class="absolute inset-0 bg-slate-950/50 backdrop-blur-[2px]" x-on:click="dismissible && close()" aria-hidden="true"></div>

    <div
        x-ref="panel"
        tabindex="-1"
        x-show="open"
        x-transition:enter="transition-[inset-inline-end] ease-out duration-300"
        x-transition:enter-start="[inset-inline-end:-100%]"
        x-transition:enter-end="end-0"
        x-transition:leave="transition-[inset-inline-end] ease-in duration-200"
        x-transition:leave-start="end-0"
        x-transition:leave-end="[inset-inline-end:-100%]"
        class="absolute inset-y-0 end-0 flex w-full flex-col bg-white shadow-overlay ring-1 ring-black/5 dark:bg-slate-800 dark:ring-white/10 {{ $sizes[$size] ?? $sizes['md'] }}"
    >
        <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-6 py-4 dark:border-slate-700">
            <div class="min-w-0">
                @if ($title)
                    <h2 id="{{ $id ?? 'drawer' }}-title" class="text-base font-semibold text-slate-900 dark:text-white">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">{{ $description }}</p>
                @endif
            </div>
            @if ($dismissible)
                <button
                    type="button"
                    x-on:click="close"
                    class="shrink-0 rounded-[7px] p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-700 dark:hover:text-slate-200"
                    aria-label="{{ __('actions.close') }}"
                >
                    <x-ui.icon name="x-mark" class="size-5" aria-hidden="true" />
                </button>
            @endif
        </div>

        <div class="flex-1 overflow-y-auto p-6">
            {{ $slot }}
        </div>

        @if (! empty($footer))
            <div class="flex flex-wrap items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-6 py-4 dark:border-slate-700 dark:bg-slate-800/60">
                {{ $footer }}
            </div>
        @endif
    </div>
</div>