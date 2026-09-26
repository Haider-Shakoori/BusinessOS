@extends('layouts.app')

@section('content')
<x-app.page icon="pencil" :title="__('suppliers.edit')" :subtitle="$supplier->name">
    <x-ui.card class="max-w-4xl">
        <form method="POST" action="{{ route('suppliers.update', $supplier) }}" class="grid gap-4 sm:grid-cols-2">
            @csrf
            @method('PATCH')
            <x-ui.input name="code" :label="__('suppliers.fields.code')" :value="old('code', $supplier->code)" />
            <x-ui.input name="name" :label="__('suppliers.fields.name')" :value="old('name', $supplier->name)" required />
            <x-ui.input name="email" type="email" :label="__('suppliers.fields.email')" :value="old('email', $supplier->email)" />
            <x-ui.input name="phone" :label="__('suppliers.fields.phone')" :value="old('phone', $supplier->phone)" />
            <div class="sm:col-span-2"><x-ui.input name="address" :label="__('suppliers.fields.address')" :value="old('address', $supplier->address)" /></div>
            <x-ui.input name="opening_balance" type="number" step="0.0001" min="0" :label="__('suppliers.fields.opening_balance')" :value="old('opening_balance', $supplier->opening_balance)" />
            <x-ui.input name="opening_balance_date" type="date" :label="__('suppliers.fields.opening_balance_date')" :value="old('opening_balance_date', $supplier->opening_balance_date?->format('Y-m-d'))" />
            <div class="sm:col-span-2"><x-ui.textarea name="notes" :label="__('suppliers.fields.notes')" :value="old('notes', $supplier->notes)" rows="4" /></div>
            <div class="sm:col-span-2">
                <input type="hidden" name="is_active" value="0">
                <x-ui.toggle name="is_active" :label="__('suppliers.active')" :checked="(bool) old('is_active', $supplier->is_active)" />
            </div>
            <div class="sm:col-span-2 flex justify-end gap-2">
                <x-ui.button href="{{ route('suppliers.show', $supplier) }}" variant="secondary">{{ __('actions.cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check-circle">{{ __('actions.save') }}</x-ui.button>
            </div>
        </form>
    </x-ui.card>
</x-app.page>
@endsection
