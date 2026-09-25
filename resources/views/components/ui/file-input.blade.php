@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'helper' => null,
    'accept' => null,
    'required' => false,
    'disabled' => false,
    'error' => null,
])

@php
    $fieldId = $id ?? $name;
    $hasError = ! empty($error) || ($name && $errors->has($name));
    $resolvedError = $error ?: ($name ? $errors->first($name) : null);
@endphp

<div x-data="{ fileName: '' }">
    @if ($label)
        <label for="{{ $fieldId }}" class="mb-1.5 block text-[12px] font-semibold text-slate-700 dark:text-slate-200">
            {{ $label }}
            @if ($required)
                <span class="text-red-500" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    <label
        for="{{ $fieldId }}"
        class="group flex cursor-pointer items-center justify-between gap-3 rounded-[7px] border border-dashed border-slate-300 bg-white px-4 py-2.5 text-sm text-slate-500 shadow-sm transition-colors duration-150 hover:border-brand-400 hover:bg-slate-50 focus-within:ring-2 focus-within:ring-brand-500/30 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-400 dark:hover:border-brand-400 dark:hover:bg-slate-800/60"
    >
        <span class="flex min-w-0 items-center gap-2">
            <x-ui.icon name="document-text" class="size-4 shrink-0 text-slate-400 dark:text-slate-500" />
            <span class="min-w-0 truncate">
                <span x-show="!fileName" class="font-medium text-slate-700 dark:text-slate-200">{{ __('actions.upload') }}</span>
                <span x-show="fileName" x-text="fileName" class="truncate font-medium text-slate-900 dark:text-white"></span>
            </span>
        </span>
        <span class="shrink-0 text-[11px] text-slate-400 dark:text-slate-500">{{ $accept }}</span>

        <input
            id="{{ $fieldId }}"
            type="file"
            @if ($name) name="{{ $name }}" @endif
            @if ($accept) accept="{{ $accept }}" @endif
            @if ($required) required @endif
            @if ($disabled) disabled @endif
            @if ($resolvedError) aria-invalid="true" @endif
            x-on:change="fileName = $event.target.files[0] ? $event.target.files[0].name : ''"
            class="sr-only"
        >
    </label>

    @if ($helper && ! $resolvedError)
        <p class="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">{{ $helper }}</p>
    @endif

    @if ($resolvedError)
        <p id="{{ $fieldId }}-error" class="mt-1.5 text-[11px] font-medium text-red-600 dark:text-red-400">{{ $resolvedError }}</p>
    @endif

    {{ $slot }}
</div>