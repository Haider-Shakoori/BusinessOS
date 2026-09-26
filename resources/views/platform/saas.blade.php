@extends('layouts.app')

@section('content')
<x-app.page icon="saas" :title="__('saas.title')" :subtitle="__('saas.subtitle')">
    @if (session('status'))
        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
    @endif
    @if ($errors->any())
        <div class="mb-5"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div>
    @endif

    <div class="grid gap-5 xl:grid-cols-[380px_minmax(0,1fr)]">
        <x-ui.card>
            <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('saas.new_plan') }}</h2>
            <form method="POST" action="{{ route('platform.saas.plans.store') }}" class="mt-4 space-y-4">
                @csrf
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-1">
                    <x-ui.input name="key" :label="__('saas.key')" :value="old('key')" required />
                    <x-ui.input name="name" :label="__('saas.name')" :value="old('name')" required />
                    <x-ui.input name="currency_code" :label="__('saas.currency')" :value="old('currency_code', 'AFN')" required maxlength="3" />
                    <x-ui.input name="sort_order" type="number" min="0" :label="__('saas.sort_order')" :value="old('sort_order', 0)" required />
                    <x-ui.input name="monthly_price" type="number" min="0" step="0.01" :label="__('saas.monthly_price')" :value="old('monthly_price')" />
                    <x-ui.input name="annual_price" type="number" min="0" step="0.01" :label="__('saas.annual_price')" :value="old('annual_price')" />
                    <x-ui.input name="limit_members" type="number" min="1" :label="__('saas.member_limit')" :value="old('limit_members')" />
                    <x-ui.input name="limit_products" type="number" min="1" :label="__('saas.product_limit')" :value="old('limit_products')" />
                    <x-ui.input name="limit_enabled_modules" type="number" min="1" :label="__('saas.module_limit')" :value="old('limit_enabled_modules')" />
                </div>

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('saas.description') }}</label>
                    <textarea name="description" rows="3" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-white">{{ old('description') }}</textarea>
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('saas.features') }}</label>
                    <textarea name="features" rows="4" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-900 dark:text-white">{{ old('features') }}</textarea>
                </div>

                <div class="flex flex-wrap gap-5">
                    <input type="hidden" name="is_active" value="0">
                    <x-ui.toggle name="is_active" :label="__('saas.active')" :checked="(bool) old('is_active', true)" />
                    <input type="hidden" name="is_default" value="0">
                    <x-ui.toggle name="is_default" :label="__('saas.default')" :checked="(bool) old('is_default', false)" />
                </div>

                <x-ui.button type="submit" class="w-full justify-center">{{ __('saas.create_plan') }}</x-ui.button>
            </form>
        </x-ui.card>

        <div class="space-y-5">
            <div>
                <h2 class="mb-3 text-base font-semibold text-slate-900 dark:text-white">{{ __('saas.plans') }}</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    @forelse($plans as $plan)
                        <x-ui.card>
                            <form method="POST" action="{{ route('platform.saas.plans.update', $plan) }}" class="space-y-3">
                                @csrf
                                @method('PATCH')
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <h3 class="font-semibold text-slate-900 dark:text-white">{{ $plan->name }}</h3>
                                        <p class="text-xs text-slate-500">{{ $plan->key }}</p>
                                    </div>
                                    <div class="flex gap-1.5">
                                        @if($plan->is_default)<x-ui.badge tone="brand">{{ __('saas.default') }}</x-ui.badge>@endif
                                        <x-ui.badge :tone="$plan->is_active ? 'success' : 'neutral'">{{ $plan->is_active ? __('saas.active') : __('system.modules.disabled') }}</x-ui.badge>
                                    </div>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <x-ui.input name="key" :label="__('saas.key')" :value="$plan->key" required />
                                    <x-ui.input name="name" :label="__('saas.name')" :value="$plan->name" required />
                                    <x-ui.input name="monthly_price" type="number" min="0" step="0.01" :label="__('saas.monthly_price')" :value="$plan->monthly_price" />
                                    <x-ui.input name="annual_price" type="number" min="0" step="0.01" :label="__('saas.annual_price')" :value="$plan->annual_price" />
                                    <x-ui.input name="currency_code" :label="__('saas.currency')" :value="$plan->currency_code" required maxlength="3" />
                                    <x-ui.input name="sort_order" type="number" min="0" :label="__('saas.sort_order')" :value="$plan->sort_order" required />
                                    <x-ui.input name="limit_members" type="number" min="1" :label="__('saas.member_limit')" :value="data_get($plan->limits, 'members')" />
                                    <x-ui.input name="limit_products" type="number" min="1" :label="__('saas.product_limit')" :value="data_get($plan->limits, 'products')" />
                                    <x-ui.input name="limit_enabled_modules" type="number" min="1" :label="__('saas.module_limit')" :value="data_get($plan->limits, 'enabled_modules')" />
                                </div>

                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('saas.description') }}</label>
                                    <textarea name="description" rows="2" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">{{ $plan->description }}</textarea>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('saas.features') }}</label>
                                    <textarea name="features" rows="3" class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-900">{{ implode(PHP_EOL, $plan->features ?? []) }}</textarea>
                                </div>

                                <div class="flex flex-wrap gap-5">
                                    <input type="hidden" name="is_active" value="0">
                                    <x-ui.toggle name="is_active" :label="__('saas.active')" :checked="$plan->is_active" />
                                    <input type="hidden" name="is_default" value="0">
                                    <x-ui.toggle name="is_default" :label="__('saas.default')" :checked="$plan->is_default" />
                                </div>
                                <x-ui.button type="submit" variant="secondary" class="w-full justify-center">{{ __('saas.save_plan') }}</x-ui.button>
                            </form>
                        </x-ui.card>
                    @empty
                        <x-ui.card><p class="text-sm text-slate-500">{{ __('saas.new_plan') }}</p></x-ui.card>
                    @endforelse
                </div>
            </div>

            <div>
                <h2 class="mb-3 text-base font-semibold text-slate-900 dark:text-white">{{ __('saas.businesses') }}</h2>
                <div class="space-y-3">
                    @foreach($businesses as $business)
                        @php
                            $subscription = $business->subscription;
                            $businessUsage = $usage->get($business->id, []);
                        @endphp
                        <x-ui.card>
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <h3 class="font-semibold text-slate-900 dark:text-white">{{ $business->name }}</h3>
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <x-ui.badge tone="neutral">{{ __('saas.members') }}: {{ $businessUsage['members'] ?? 0 }}</x-ui.badge>
                                        <x-ui.badge tone="neutral">{{ __('saas.products') }}: {{ $businessUsage['products'] ?? 0 }}</x-ui.badge>
                                        <x-ui.badge tone="neutral">{{ __('saas.enabled_modules') }}: {{ $businessUsage['enabled_modules'] ?? 0 }}</x-ui.badge>
                                    </div>
                                </div>
                                @if($subscription)
                                    <x-ui.badge :tone="in_array($subscription->status, ['active', 'trialing'], true) ? 'success' : 'danger'">{{ $subscription->status }}</x-ui.badge>
                                @endif
                            </div>

                            <form method="POST" action="{{ route('platform.saas.subscriptions.update', $business) }}" class="mt-4 grid gap-3 md:grid-cols-5">
                                @csrf
                                @method('PATCH')
                                <x-ui.select name="saas_plan_id" :label="__('saas.plan')">
                                    <option value="">{{ __('saas.unassigned') }}</option>
                                    @foreach($plans->where('is_active', true) as $plan)
                                        <option value="{{ $plan->id }}" @selected((string) $subscription?->saas_plan_id === (string) $plan->id)>{{ $plan->name }}</option>
                                    @endforeach
                                </x-ui.select>
                                <x-ui.select name="status" :label="__('saas.status')" required>
                                    @foreach($statuses as $status)
                                        <option value="{{ $status }}" @selected(($subscription?->status ?? 'trialing') === $status)>{{ $status }}</option>
                                    @endforeach
                                </x-ui.select>
                                <x-ui.input name="trial_ends_at" type="datetime-local" :label="__('saas.trial_ends')" :value="$subscription?->trial_ends_at?->format('Y-m-d\TH:i')" />
                                <x-ui.input name="current_period_ends_at" type="datetime-local" :label="__('saas.period_ends')" :value="$subscription?->current_period_ends_at?->format('Y-m-d\TH:i')" />
                                <div class="flex items-end">
                                    <x-ui.button type="submit" class="w-full justify-center">{{ __('saas.save_subscription') }}</x-ui.button>
                                </div>
                            </form>
                        </x-ui.card>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app.page>
@endsection
