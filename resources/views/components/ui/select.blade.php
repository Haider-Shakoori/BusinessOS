@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'placeholder' => null,
    'helper' => null,
    'error' => null,
    'required' => false,
    'disabled' => false,
])

@php
    $fieldId = $id ?? $name;
    $hasError = ! empty($error) || ($name && $errors->has($name));
    $resolvedError = $error ?: ($name ? $errors->first($name) : null);
    $selected = old($name) ?? $attributes->get('value', '');
@endphp

<div>
    @if ($label)
        <label for="{{ $fieldId }}" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-200">
            {{ $label }}
            @if ($required)
                <span class="text-red-500" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    <div class="relative">
        <select
            @if ($fieldId) id="{{ $fieldId }}" @endif
            @if ($name) name="{{ $name }}" @endif
            @if ($required) required @endif
            @if ($disabled) disabled @endif
            @if ($resolvedError) aria-invalid="true" @endif
            {{ $attributes->except(['id', 'name', 'required', 'disabled' , 'value'])->merge(['class' =>
                'block w-full appearance-none rounded-lg border bg-white px-3 py-2 pe-10 text-sm text-gray-900 shadow-sm
                transition-colors duration-150 focus:outline-none focus:ring-2
                disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-500
                dark:bg-gray-800 dark:text-gray-100 dark:disabled:bg-gray-800/60'
                .($hasError
                    ? ' border-red-400 focus:border-red-500 focus:ring-red-500/25 dark:border-red-500/70'
                    : ' border-gray-300 focus:border-brand-500 focus:ring-brand-500/30 dark:border-gray-600 dark:focus:border-brand-400')
            ]) }}
        >
            @if ($placeholder)
                <option value="" disabled @if ($selected === '') selected @endif>{{ $placeholder }}</option>
            @endif
            {{ $slot }}
        </select>

        <div class="pointer-events-none absolute inset-y-0 end-0 flex items-center pe-3 text-gray-400 dark:text-gray-500">
            <x-ui.icon name="chevron-down" class="size-4" />
        </div>
    </div>

    @if ($helper && ! $resolvedError)
        <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{{ $helper }}</p>
    @endif

    @if ($resolvedError)
        <p id="{{ $fieldId }}-error" class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">{{ $resolvedError }}</p>
    @endif
</div>