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
        <label for="{{ $fieldId }}" class="mb-1.5 block text-[12px] font-semibold text-slate-700 dark:text-slate-200">
            {{ $label }}
        </label>
    @endif

    <div class="relative">
        <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-slate-400 dark:text-slate-500">
            <x-ui.icon name="search" class="size-4" />
        </div>

        <input
            type="search"
            @if ($fieldId) id="{{ $fieldId }}" @endif
            @if ($name) name="{{ $name }}" @endif
            placeholder="{{ $placeholder ?? __('common.search') }}"
            x-model="value"
            class="block w-full rounded-[7px] border border-slate-300 bg-white py-[8px] pe-10 ps-10 text-[13px] text-slate-900 shadow-sm
                placeholder:text-slate-400 transition-colors duration-150 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20
                dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-brand-400"
        >

        @if ($clearable)
            <button
                type="button"
                x-show="value.length > 0"
                x-cloak
                x-on:click="value = ''"
                class="absolute inset-y-0 end-0 flex items-center pe-2.5 text-slate-400 transition-colors hover:text-slate-700 dark:hover:text-slate-200"
                aria-label="{{ __('actions.clear') }}"
            >
                <x-ui.icon name="x-mark" class="size-4" />
            </button>
        @endif
    </div>

    @if ($helper && ! $error)
        <p class="mt-1.5 text-[11px] leading-4 text-slate-500 dark:text-slate-400">{{ $helper }}</p>
    @endif

    @if ($error)
        <p class="mt-1.5 text-[11px] font-medium text-red-600 dark:text-red-400">{{ $error }}</p>
    @endif
</div>
