@props([
    'name' => null,
    'id' => null,
    'value' => 1,
    'label' => null,
    'description' => null,
    'helper' => null,
    'error' => null,
    'required' => false,
    'disabled' => false,
    'checked' => false,
])

@php
    $fieldId = $id ?? ($name . '_' . $value);
    $oldValue = $name ? old($name) : null;
    $isChecked = $oldValue !== null ? $oldValue == $value : (bool) $checked;
    $hasError = ! empty($error) || ($name && $errors->has($name));
    $resolvedError = $error ?: ($name ? $errors->first($name) : null);
@endphp

<div>
    <label for="{{ $fieldId }}" class="flex items-start gap-3">
        <input
            type="radio"
            @if ($fieldId) id="{{ $fieldId }}" @endif
            @if ($name) name="{{ $name }}" @endif
            value="{{ $value }}"
            @if ($isChecked) checked @endif
            @if ($required) required @endif
            @if ($disabled) disabled @endif
            @if ($resolvedError) aria-invalid="true" @endif
            {{ $attributes->except(['id', 'name', 'value', 'checked', 'required', 'disabled'])->merge(['class' =>
                'mt-0.5 size-4 shrink-0 rounded-full border-gray-300 bg-white text-brand-600 shadow-sm
                accent-brand-600 transition-colors duration-150
                focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600
                disabled:cursor-not-allowed disabled:opacity-60
                dark:border-gray-600 dark:bg-gray-800'
                .($hasError ? ' border-red-400 dark:border-red-500/70' : '')
            ]) }}
        >

        <span class="select-none text-sm">
            <span class="font-medium text-gray-800 dark:text-gray-100">
                {{ $label }}
                @if ($required)
                    <span class="text-red-500" aria-hidden="true">*</span>
                @endif
            </span>
            @if ($description)
                <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400">{{ $description }}</span>
            @endif
        </span>
    </label>

    @if ($helper && ! $resolvedError)
        <p class="ms-7 mt-1.5 text-xs text-gray-500 dark:text-gray-400">{{ $helper }}</p>
    @endif

    @if ($resolvedError)
        <p id="{{ $fieldId }}-error" class="ms-7 mt-1.5 text-xs font-medium text-red-600 dark:text-red-400">{{ $resolvedError }}</p>
    @endif
</div>