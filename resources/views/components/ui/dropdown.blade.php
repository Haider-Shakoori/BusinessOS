@props([
    'align' => 'end',
    'width' => 'w-48',
    'label' => null,
])

<div
    x-data="dropdownMenu"
    x-on:click.outside="close"
    class="relative inline-flex"
    @keydown.window.escape="onEscape"
>
    <button
        type="button"
        x-on:click="toggle"
        aria-haspopup="menu"
        :aria-expanded="open.toString()"
        {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-lg text-sm font-medium text-gray-700 transition-colors hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:text-gray-200 dark:hover:text-white']) }}
    >
        {{ $trigger }}
        @if (empty($trigger))
            {{ $label ?? 'Menu' }}
        @endif
        <x-ui.icon name="chevron-down" class="size-4" aria-hidden="true" />
    </button>

    <div
        x-ref="menu"
        tabindex="-1"
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        role="menu"
        aria-label="{{ $label ?? 'Dropdown menu' }}"
        class="absolute z-40 mt-2 origin-top rounded-xl border border-gray-200 bg-white p-1 shadow-popover dark:border-gray-700 dark:bg-gray-800 {{ $width }} {{ $align === 'start' ? 'start-0' : 'end-0' }}"
        x-cloak
    >
        {{ $items }}
    </div>
</div>