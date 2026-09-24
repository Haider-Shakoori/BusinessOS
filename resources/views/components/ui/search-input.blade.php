@props([
    'name' => 'search',
    'id' => null,
    'label' => null,
    'placeholder' => null,
    'helper' => null,
    'error' => null,
    'clearable' => false,
])

@php
    $fieldId = $id ?? $name;
@endphp

<div x-data="{ value: @js(old($name, $attributes->get('value', ''))) }" {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $fieldId }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-200">
            {{ $label }}
        </label>
    @endif

    <div class="relative">
        <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-gray-400 dark:text-gray-500">
            <x-ui.icon name="search" class="size-4" />
        </div>

        <input
            type="search"
            @if ($fieldId) id="{{ $fieldId }}" @endif
            @if ($name) name="{{ $name }}" @endif
            placeholder="{{ $placeholder ?? 'Search…' }}"
            x-model="value"
            class="block w-full rounded-lg border-gray-300 border bg-white ps-10 pe-10 py-2 text-sm text-gray-900 shadow-sm
                placeholder:text-gray-400 transition-colors duration-150 focus:outline-none focus:ring-2 focus:border-brand-500 focus:ring-brand-500/30
                dark:bg-gray-800 dark:text-gray-100 dark:placeholder:text-gray-500 dark:border-gray-600 dark:focus:border-brand-400"
        >

        @if ($clearable)
            <button
                type="button"
                x-show="value.length > 0"
                x-cloak
                x-on:click="value = ''"
                class="absolute inset-y-0 end-0 flex items-center pe-2.5 text-gray-400 transition-colors hover:text-gray-600 dark:hover:text-gray-200"
                aria-label="{{ __('Clear search') }}"
            >
                <x-ui.icon name="x-mark" class="size-4" />
            </button>
        @endif
    </div>

    @if ($helper && ! $error)
        <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{{ $helper }}</p>
    @endif

    @if ($error)
        <p class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">{{ $error }}</p>
    @endif
</div>