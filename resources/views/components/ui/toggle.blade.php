@props([
    'name' => null,
    'id' => null,
    'value' => 1,
    'label' => null,
    'description' => null,
    'helper' => null,
    'error' => null,
    'disabled' => false,
    'checked' => false,
])

@php
    $fieldId = $id ?? $name;
    $oldValue = $name ? old($name) : null;
    $isChecked = $oldValue !== null ? (bool) $oldValue : (bool) $checked;
    $resolvedError = $error ?: ($name ? $errors->first($name) : null);
@endphp

<div x-data="{ on: {{ $isChecked ? 'true' : 'false' }} }">
    <div class="flex items-center justify-between gap-4">
        <span class="text-sm">
            <span class="font-medium text-slate-800 dark:text-slate-100">
                {{ $label }}
                <span class="sr-only">{{ $label }}</span>
            </span>
            @if ($description)
                <span class="mt-0.5 block text-[11px] font-normal text-slate-500 dark:text-slate-400">{{ $description }}</span>
            @endif
        </span>

        <button
            type="button"
            role="switch"
            @if ($fieldId) id="{{ $fieldId }}" @endif
            @if ($disabled) disabled @endif
            x-on:click="on = !on"
            :aria-checked="on.toString()"
            aria-checked="{{ $isChecked ? 'true' : 'false' }}"
            aria-labelledby="{{ $label ? $fieldId.'-label' : null }}"
            class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors duration-150
                focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600
                disabled:cursor-not-allowed disabled:opacity-60"
            :class="on ? 'bg-brand-600' : 'bg-slate-300 dark:bg-slate-600'"
        >
            <span
                x-cloak
                aria-hidden="true"
                class="rtl-flip inline-block size-4 rounded-full bg-white shadow transition-transform duration-150"
                :class="on ? 'translate-x-[18px]' : 'translate-x-0.5'"
            ></span>
        </button>
    </div>

    @if ($name)
        <input
            type="checkbox"
            name="{{ $name }}"
            value="{{ $value }}"
            class="sr-only"
            x-model="on"
            tabindex="-1"
            @if ($isChecked) checked @endif
        >
    @endif

    @if ($helper && ! $resolvedError)
        <p class="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">{{ $helper }}</p>
    @endif

    @if ($resolvedError)
        <p class="mt-1.5 text-[11px] font-medium text-red-600 dark:text-red-400">{{ $resolvedError }}</p>
    @endif
</div>