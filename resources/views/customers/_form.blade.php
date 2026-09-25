@php
    $customer = $customer ?? null;
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
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('customers.information') }}</h2>
        </x-slot:header>

        <div class="grid gap-x-5 gap-y-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-ui.input
                    name="name"
                    :label="__('customers.name')"
                    :value="old('name', $customer?->name)"
                    :disabled="! $editable"
                    maxlength="100"
                    required
                />
            </div>

            <div class="sm:col-span-2">
                <x-ui.input
                    name="company_name"
                    :label="__('customers.company_name')"
                    :helper="__('customers.company_name_helper')"
                    :value="old('company_name', $customer?->company_name)"
                    :disabled="! $editable"
                    maxlength="100"
                />
            </div>

            <x-ui.input
                type="email"
                name="email"
                :label="__('customers.email')"
                :value="old('email', $customer?->email)"
                :disabled="! $editable"
                maxlength="255"
            />

            <x-ui.input
                type="tel"
                name="phone"
                :label="__('customers.phone')"
                :value="old('phone', $customer?->phone)"
                :disabled="! $editable"
                maxlength="30"
            />

            <div class="sm:col-span-2">
                <x-ui.textarea
                    name="address"
                    :label="__('customers.address')"
                    :value="old('address', $customer?->address)"
                    rows="3"
                    :disabled="! $editable"
                    maxlength="500"
                />
            </div>

            <div class="sm:col-span-2">
                <x-ui.input
                    type="number"
                    name="opening_balance"
                    :label="__('customers.opening_balance')"
                    :helper="__('customers.opening_balance_helper')"
                    :value="old('opening_balance', $customer?->opening_balance ?: null)"
                    :disabled="! $editable"
                    min="0"
                    max="999999999999"
                    step="0.0001"
                />
            </div>

            <div class="sm:col-span-2">
                <x-ui.input
                    type="date"
                    name="opening_balance_date"
                    :label="__('customers.opening_balance_date')"
                    :helper="__('customers.opening_balance_date_helper')"
                    :value="old('opening_balance_date', $customer?->opening_balance_date?->format('Y-m-d'))"
                    :disabled="! $editable"
                />
            </div>

            <div class="sm:col-span-2">
                <x-ui.textarea
                    name="notes"
                    :label="__('customers.notes')"
                    :helper="__('customers.notes_helper')"
                    :value="old('notes', $customer?->notes)"
                    rows="4"
                    :disabled="! $editable"
                    maxlength="2000"
                />
            </div>
        </div>
    </x-ui.card>

    @if ($editable)
        <div class="flex flex-wrap items-center justify-end gap-3">
            <x-ui.button type="submit" icon="check-circle">
                {{ $method === 'PATCH' ? __('actions.update') : __('actions.create') }}
            </x-ui.button>
            <x-ui.button variant="secondary" href="{{ route('customers.index') }}">
                {{ __('actions.cancel') }}
            </x-ui.button>
        </div>
    @endif
</form>