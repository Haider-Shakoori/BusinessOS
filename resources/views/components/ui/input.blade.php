@props([
    'type' => 'text',
    'name' => null,
    'id' => null,
    'label' => null,
    'placeholder' => null,
    'helper' => null,
    'error' => null,
    'required' => false,
    'disabled' => false,
    'readonly' => false,
    'leading' => null,
    'trailing' => null,
])

@php
    $fieldId = $id ?? $name;
    $hasError = ! empty($error) || ($name && $errors->has($name));
    $resolvedError = $error ?: ($name ? $errors->first($name) : null);

    $hasOld = $name && ! $attributes->has('value') && old($name) !== null;
    $value = $type === 'password' ? '' : ($hasOld ? old($name) : $attributes->get('value', $slot->isNotEmpty() ? $slot : ''));

    $hasLeadingIcon = ! empty($leading);
    $hasTrailingIcon = ! empty($trailing);

    $classes = 'block w-full rounded-[7px] border bg-white px-3 py-[8px] text-[13px] text-slate-900 shadow-sm
        placeholder:text-slate-400 transition-colors duration-150
        focus:outline-none focus:ring-2
        disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500
        readonly:bg-slate-50
        dark:bg-slate-900 dark:text-slate-100 dark:placeholder:text-slate-500 dark:disabled:bg-slate-800/60 dark:readonly:bg-slate-800/70'
        .($hasError
            ? ' border-red-400 focus:border-red-500 focus:ring-red-500/20 dark:border-red-500/70'
            : ' border-slate-300 focus:border-brand-500 focus:ring-brand-500/20 dark:border-slate-600 dark:focus:border-brand-400')
        .($hasLeadingIcon ? ' ps-10' : '')
        .($hasTrailingIcon ? ' pe-10' : '');
@endphp

<div>
    @if ($label)
        <label for="{{ $fieldId }}" class="mb-1.5 block text-[12px] font-semibold text-slate-700 dark:text-slate-200">
            {{ $label }}
            @if ($required)
                <span class="text-red-500" aria-hidden="true">*</span>
            @endif
        </label>
    @endif

    <div class="relative">
        @if (! empty($leading))
            <div class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-slate-400 dark:text-slate-500">
                {{ $leading }}
            </div>
        @endif

        <input
            type="{{ $type }}"
            @if ($fieldId) id="{{ $fieldId }}" @endif
            @if ($name) name="{{ $name }}" @endif
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            value="{{ $value }}"
            @if ($required) required @endif
            @if ($disabled) disabled @endif
            @if ($readonly) readonly @endif
            @if ($resolvedError) aria-invalid="true" @endif
            @if ($resolvedError) aria-describedby="{{ $fieldId }}-error" @endif
            {{ $attributes->except(['id', 'name', 'placeholder', 'value', 'required', 'disabled', 'readonly', 'type'])->merge(['class' => $classes]) }}
        >

        @if (! empty($trailing))
            <div class="absolute inset-y-0 end-0 flex items-center pe-3 text-slate-400 dark:text-slate-500">
                {{ $trailing }}
            </div>
        @endif
    </div>

    @if ($helper && ! $resolvedError)
        <p class="mt-1.5 text-[11px] leading-4 text-slate-500 dark:text-slate-400">{{ $helper }}</p>
    @endif

    @if ($resolvedError)
        <p id="{{ $fieldId }}-error" class="mt-1.5 text-[11px] font-medium text-red-600 dark:text-red-400">{{ $resolvedError }}</p>
    @endif
</div>
