@php
    $tax = $tax ?? null;
    $editable = $editable ?? true;
    $method = $method ?? 'POST';
@endphp

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('taxes.information') }}</h2>
        </x-slot:header>

        <div class="grid gap-x-5 gap-y-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-ui.input
                    name="name"
                    :label="__('taxes.name')"
                    :value="old('name', $tax?->name)"
                    :disabled="! $editable"
                    maxlength="100"
                    required
                />
            </div>

            <div class="sm:col-span-2">
                <x-ui.input
                    name="rate"
                    :label="__('taxes.rate')"
                    :helper="__('taxes.rate_helper')"
                    :value="old('rate', $tax?->rate)"
                    :disabled="! $editable"
                    inputmode="decimal"
                    required
                />
            </div>
        </div>
    </x-ui.card>

    @if ($editable)
        <div class="flex flex-wrap items-center justify-end gap-3">
            <x-ui.button type="submit" icon="check-circle">
                {{ $method === 'PATCH' ? __('actions.update') : __('actions.create') }}
            </x-ui.button>
            <x-ui.button variant="secondary" href="{{ route('taxes.index') }}">
                {{ __('actions.cancel') }}
            </x-ui.button>
        </div>
    @endif
</form>