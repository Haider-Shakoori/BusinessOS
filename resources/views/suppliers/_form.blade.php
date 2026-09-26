@php
    $supplier = $supplier ?? null;
    $method = $method ?? 'POST';
@endphp

<form method="POST" action="{{ $action }}" class="space-y-5">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <x-ui.card>
        <x-slot:header>
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('suppliers.details') }}</h2>
        </x-slot:header>

        <div class="grid gap-x-5 gap-y-4 sm:grid-cols-2">
            <x-ui.input
                name="code"
                :label="__('suppliers.code')"
                :value="old('code', $supplier?->code)"
                maxlength="50"
            />
            <x-ui.input
                name="name"
                :label="__('suppliers.name')"
                :value="old('name', $supplier?->name)"
                maxlength="255"
                required
            />
            <x-ui.input
                type="email"
                name="email"
                :label="__('suppliers.email')"
                :value="old('email', $supplier?->email)"
                maxlength="255"
            />
            <x-ui.input
                type="tel"
                name="phone"
                :label="__('suppliers.phone')"
                :value="old('phone', $supplier?->phone)"
                maxlength="100"
            />
            <div class="sm:col-span-2">
                <x-ui.textarea
                    name="address"
                    :label="__('suppliers.address')"
                    :value="old('address', $supplier?->address)"
                    rows="3"
                    maxlength="2000"
                />
            </div>
            <x-ui.input
                type="number"
                name="opening_balance"
                :label="__('suppliers.opening_balance')"
                :value="old('opening_balance', $supplier?->opening_balance ?: null)"
                min="0"
                max="999999999999.9999"
                step="0.0001"
            />
            <x-ui.input
                type="date"
                name="opening_balance_date"
                :label="__('suppliers.opening_balance_date')"
                :value="old('opening_balance_date', $supplier?->opening_balance_date?->format('Y-m-d'))"
            />
            <div class="sm:col-span-2">
                <x-ui.textarea
                    name="notes"
                    :label="__('suppliers.notes')"
                    :value="old('notes', $supplier?->notes)"
                    rows="4"
                    maxlength="4000"
                />
            </div>
        </div>
    </x-ui.card>

    <div class="flex flex-wrap items-center justify-end gap-3">
        <x-ui.button type="submit" icon="check-circle">{{ __('suppliers.save') }}</x-ui.button>
        <x-ui.button variant="secondary" href="{{ $supplier ? route('suppliers.show', $supplier) : route('suppliers.index') }}">
            {{ __('actions.cancel') }}
        </x-ui.button>
    </div>
</form>
