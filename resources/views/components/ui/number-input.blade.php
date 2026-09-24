@props([
    'name' => null,
    'id' => null,
    'label' => null,
    'placeholder' => null,
    'helper' => null,
    'error' => null,
    'required' => false,
    'disabled' => false,
    'readonly' => false,
    'step' => null,
    'min' => null,
    'max' => null,
])

<x-ui.input
    :name="$name"
    :id="$id"
    :label="$label"
    :placeholder="$placeholder"
    :helper="$helper"
    :error="$error"
    :required="$required"
    :disabled="$disabled"
    :readonly="$readonly"
    type="number"
    inputmode="decimal"
    autocomplete="off"
    :step="$step"
    :min="$min"
    :max="$max"
    {{ $attributes->class(['text-end tabular-nums']) }}
>{{ $slot }}</x-ui.input>