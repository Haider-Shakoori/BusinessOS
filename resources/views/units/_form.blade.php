@php
    $unit = $unit ?? null;
    $editable = $editable ?? true;
    $method = $method ?? 'POST';
@endphp

<form method="POST" action="{{ $action }}" class="space-y-6">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('units.information') }}</h2>
        </x-slot:header>

        <div class="grid gap-x-6 gap-y-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-ui.input
                    name="name"
                    :label="__('units.name')"
                    :value="old('name', $unit?->name)"
                    :disabled="! $editable"
                    maxlength="100"
                    required
                />
            </div>

            <div class="sm:col-span-2">
                <x-ui.input
                    name="short_name"
                    :label="__('units.short_name')"
                    :helper="__('units.short_name_helper')"
                    :value="old('short_name', $unit?->short_name)"
                    :disabled="! $editable"
                    maxlength="20"
                />
            </div>
        </div>
    </x-ui.card>

    @if ($editable)
        <div class="flex flex-wrap items-center justify-end gap-3">
            <x-ui.button type="submit" icon="check-circle">
                {{ $method === 'PATCH' ? __('actions.update') : __('actions.create') }}
            </x-ui.button>
            <x-ui.button variant="secondary" href="{{ route('units.index') }}">
                {{ __('actions.cancel') }}
            </x-ui.button>
        </div>
    @endif
</form>