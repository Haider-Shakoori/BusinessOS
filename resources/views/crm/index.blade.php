@extends('layouts.app')

@section('content')
<x-app.page icon="contact-card" :title="__('operations.crm.title')" :subtitle="__('operations.crm.subtitle')">
    @if (session('status')) <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div> @endif

    <x-ui.card>
        <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('operations.crm.new_lead') }}</h2></x-slot:header>
        <form method="POST" action="{{ route('crm.leads.store') }}" class="grid gap-4 md:grid-cols-4">
            @csrf
            <x-ui.input name="name" :label="__('operations.crm.name')" required />
            <x-ui.input name="company" :label="__('operations.crm.company')" />
            <x-ui.input name="email" type="email" :label="__('operations.crm.email')" />
            <x-ui.input name="phone" :label="__('operations.crm.phone')" />
            <x-ui.select name="status" :label="__('operations.crm.status')" required>
                @foreach(['new','contacted','qualified','won','lost'] as $status)<option value="{{ $status }}">{{ __('operations.crm.'.$status) }}</option>@endforeach
            </x-ui.select>
            <x-ui.input name="source" :label="__('operations.crm.source')" />
            <x-ui.input name="estimated_value" type="number" step="0.0001" min="0" :label="__('operations.crm.estimated_value')" />
            <x-ui.input name="next_follow_up_at" type="datetime-local" :label="__('operations.crm.next_follow_up')" />
            <div class="md:col-span-3"><x-ui.input name="notes" :label="__('operations.crm.notes')" /></div>
            <div class="flex items-end justify-end"><x-ui.button type="submit" icon="plus">{{ __('operations.crm.new_lead') }}</x-ui.button></div>
        </form>
    </x-ui.card>

    <div class="mt-5 grid gap-4 xl:grid-cols-2">
        @forelse($leads as $lead)
            <x-ui.card>
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex items-center gap-2"><h3 class="font-semibold text-slate-900 dark:text-white">{{ $lead->name }}</h3><x-ui.badge tone="neutral">{{ __('operations.crm.'.$lead->status) }}</x-ui.badge></div>
                        <p class="mt-1 text-sm text-slate-500">{{ $lead->company }} @if($lead->phone) · {{ $lead->phone }} @endif</p>
                    </div>
                    @if($lead->next_follow_up_at)<span class="text-xs text-slate-500">{{ $lead->next_follow_up_at->format('Y-m-d H:i') }}</span>@endif
                </div>
                @if($lead->notes)<p class="mt-3 text-sm text-slate-600 dark:text-slate-300">{{ $lead->notes }}</p>@endif
                <form method="POST" action="{{ route('crm.leads.activities.store', $lead) }}" class="mt-4 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-3 dark:border-slate-700">
                    @csrf
                    <x-ui.select name="type" :label="__('operations.crm.activity_type')" required>
                        @foreach(['call','meeting','email','note','follow_up'] as $type)<option value="{{ $type }}">{{ __('operations.crm.'.($type === 'email' ? 'email_activity' : ($type === 'note' ? 'note_activity' : $type))) }}</option>@endforeach
                    </x-ui.select>
                    <div class="sm:col-span-2"><x-ui.input name="note" :label="__('operations.crm.notes')" required /></div>
                    <div class="sm:col-span-3 flex justify-end"><x-ui.button type="submit" variant="secondary" size="sm" icon="plus">{{ __('operations.crm.activity') }}</x-ui.button></div>
                </form>
            </x-ui.card>
        @empty
            <x-ui.card><p class="text-sm text-slate-500">{{ __('operations.crm.no_leads') }}</p></x-ui.card>
        @endforelse
    </div>
</x-app.page>
@endsection
