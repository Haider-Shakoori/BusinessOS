@php
    $category = $category ?? null;
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
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('categories.information') }}</h2>
        </x-slot:header>

        <div class="grid gap-x-5 gap-y-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-ui.input
                    name="name"
                    :label="__('categories.name')"
                    :value="old('name', $category?->name)"
                    :disabled="! $editable"
                    maxlength="100"
                    required
                />
            </div>

            <div class="sm:col-span-2">
                <x-ui.textarea
                    name="description"
                    :label="__('categories.description')"
                    :helper="__('categories.description_helper')"
                    :value="old('description', $category?->description)"
                    rows="3"
                    :disabled="! $editable"
                    maxlength="500"
                />
            </div>
        </div>
    </x-ui.card>

    @if ($editable)
        <div class="flex flex-wrap items-center justify-end gap-3">
            <x-ui.button type="submit" icon="check-circle">
                {{ $method === 'PATCH' ? __('actions.update') : __('actions.create') }}
            </x-ui.button>
            <x-ui.button variant="secondary" href="{{ route('categories.index') }}">
                {{ __('actions.cancel') }}
            </x-ui.button>
        </div>
    @endif
</form>