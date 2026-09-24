@extends('layouts.app')

@section('content')
    <x-app.page :title="__('settings.title')" :subtitle="__('settings.subtitle', ['business' => $business?->name ?? __('auth.guest')])">
        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        @unless ($editable)
            <div class="mb-6">
                <x-ui.alert type="info">{{ __('settings.view_only') }}</x-ui.alert>
            </div>
        @endunless

        <form method="POST" action="{{ route('settings.update') }}" class="space-y-6">
            @csrf
            @method('PATCH')

            <x-ui.card>
                <x-slot:header>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('settings.general') }}</h2>
                </x-slot:header>

                <div class="grid gap-x-6 gap-y-5 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-ui.input
                            name="name"
                            :label="__('settings.business_name')"
                            :value="$business?->name"
                            :helper="__('settings.business_name_helper')"
                            :disabled="! $editable"
                            maxlength="255"
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <x-ui.textarea
                            name="general.address"
                            :label="__('settings.address')"
                            :value="$values['general.address'] ?: null"
                            rows="3"
                            :disabled="! $editable"
                            maxlength="500"
                        />
                    </div>

                    <x-ui.input
                        name="general.phone"
                        :label="__('settings.phone')"
                        :value="$values['general.phone'] ?: null"
                        :disabled="! $editable"
                        maxlength="30"
                    />

                    <x-ui.input
                        type="email"
                        name="general.email"
                        :label="__('settings.email')"
                        :value="$values['general.email'] ?: null"
                        :disabled="! $editable"
                        maxlength="255"
                    />

                    <div class="sm:col-span-2 border-t border-gray-100 pt-5 dark:border-gray-700">
                        <input type="hidden" name="general.tax_enabled" value="0">
                        <x-ui.toggle
                            name="general.tax_enabled"
                            :label="__('settings.tax_enabled')"
                            :description="__('settings.tax_enabled_helper')"
                            :checked="(bool) ($values['general.tax_enabled'] ?? false)"
                            :disabled="! $editable"
                        />
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card>
                <x-slot:header>
                    <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('settings.regional') }}</h2>
                </x-slot:header>

                <div class="grid gap-x-6 gap-y-5 sm:grid-cols-2">
                    <x-ui.select
                        name="regional.timezone"
                        :label="__('settings.timezone')"
                        :value="$values['regional.timezone']"
                        :helper="__('settings.timezone_helper')"
                        :disabled="! $editable"
                    >
                        @foreach ($timezones as $timezone)
                            <option value="{{ $timezone }}" @selected($values['regional.timezone'] === $timezone)>{{ $timezone }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select
                        name="regional.locale"
                        :label="__('settings.default_locale')"
                        :value="$values['regional.locale'] ?? config('app.locale')"
                        :helper="__('settings.default_locale_helper')"
                        :disabled="! $editable"
                    >
                        @foreach ($locales as $code => $info)
                            <option value="{{ $code }}" @selected(($values['regional.locale'] ?? config('app.locale')) === $code)>{{ $info['native'] ?? $code }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select
                        name="regional.date_format"
                        :label="__('settings.date_format')"
                        :value="$values['regional.date_format']"
                        :disabled="! $editable"
                    >
                        @foreach ($dateFormatLabels as $format => $label)
                            <option value="{{ $format }}" @selected($values['regional.date_format'] === $format)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select
                        name="regional.time_format"
                        :label="__('settings.time_format')"
                        :value="$values['regional.time_format']"
                        :disabled="! $editable"
                    >
                        @foreach ($timeFormatLabels as $format => $label)
                            <option value="{{ $format }}" @selected($values['regional.time_format'] === $format)>{{ $label }}</option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.select
                        name="regional.currency"
                        :label="__('settings.base_currency')"
                        :value="$currency_base"
                        :helper="$currency_history_locked ? __('settings.base_currency_locked_helper') : __('settings.base_currency_helper')"
                        :disabled="! $editable || $currency_history_locked"
                    >
                        @foreach ($currency_active as $currency)
                            <option value="{{ $currency->code }}" @selected($currency_base === $currency->code)>
                                {{ $currency->code }} — {{ $currency->name }}
                            </option>
                        @endforeach
                    </x-ui.select>

                    @if ($currency_history_locked)
                        <div class="sm:col-span-2">
                            <x-ui.alert type="info">{{ __('settings.base_currency_locked', ['currency' => $currency_base]) }}</x-ui.alert>
                        </div>
                    @endif
                </div>
            </x-ui.card>

            <div class="flex items-center justify-end gap-3">
                @if ($editable)
                    <x-ui.button type="submit" icon="check-circle">{{ __('settings.save') }}</x-ui.button>
                @endif
            </div>
        </form>

        <x-ui.card>
            <x-slot:header>
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('settings.currencies') }}</h2>
            </x-slot:header>

            <div class="mb-5">
                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('settings.base_currency') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {{ $currency_base }}
                    <span class="ms-1 inline-flex items-center rounded-full bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">
                        {{ __('settings.base_currency_badge') }}
                    </span>
                </p>
            </div>

            <div class="mb-5">
                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('settings.enabled_currencies') }}</h3>

                @if ($currency_enabled->isEmpty())
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('settings.no_enabled_currencies') }}</p>
                @else
                    <ul class="mt-2 space-y-2">
                        @foreach ($currency_enabled as $currency)
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700">
                                <span class="text-sm text-gray-900 dark:text-white">
                                    <span class="font-semibold" dir="ltr">{{ $currency->code }}</span>
                                    <span class="text-gray-500 dark:text-gray-400"> — {{ $currency->name }} ({{ $currency->symbol }})</span>
                                </span>
                                @if ($editable && $currency->code !== $currency_base)
                                    <form method="POST" action="{{ route('settings.currencies.destroy', $currency) }}">
                                        @csrf
                                        @method('DELETE')
                                        <x-ui.button type="submit" variant="secondary" size="sm" icon="x-circle">
                                            {{ __('settings.disable_currency') }}
                                        </x-ui.button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($editable)
                <form method="POST" action="{{ route('settings.currencies.store') }}" class="mb-5 grid gap-x-6 gap-y-5 border-t border-gray-100 pt-5 sm:grid-cols-2 dark:border-gray-700">
                    @csrf
                    <div class="sm:col-span-2">
                        <x-ui.select
                            name="currency_code"
                            :label="__('settings.enable_currency')"
                            :placeholder="__('settings.choose_currency')"
                        >
                            @foreach ($currency_active as $currency)
                                @continue($currency->code === $currency_base || $currency_enabled->pluck('code')->contains($currency->code))
                                <option value="{{ $currency->code }}">
                                    {{ $currency->code }} — {{ $currency->name }}
                                </option>
                            @endforeach
                        </x-ui.select>
                    </div>
                    <div>
                        <x-ui.button type="submit" icon="check-circle">{{ __('settings.enable') }}</x-ui.button>
                    </div>
                </form>
            @endif

            <div>
                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ __('settings.exchange_rates') }}</h3>

                @if ($currency_rates->isEmpty())
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('settings.no_exchange_rates') }}</p>
                @else
                    <div class="mt-2 overflow-x-auto">
                        <x-ui.table :caption="__('settings.exchange_rates')">
                            <x-slot:head>
                                <tr>
                                    <x-ui.th>{{ __('settings.currency') }}</x-ui.th>
                                    <x-ui.th>{{ __('settings.rate') }}</x-ui.th>
                                    <x-ui.th>{{ __('settings.effective_date') }}</x-ui.th>
                                    <x-ui.th class="text-end">
                                        <span class="sr-only">{{ __('actions.delete') }}</span>
                                    </x-ui.th>
                                </tr>
                            </x-slot:head>
                            @foreach ($currency_rates as $rate)
                                <tr>
                                    <x-ui.td>
                                        <span class="font-semibold text-gray-900 dark:text-white" dir="ltr">{{ $rate->currency_code }}</span>
                                        <span class="text-gray-500 dark:text-gray-400"> — {{ $rate->currency?->name }}</span>
                                    </x-ui.td>
                                    <x-ui.td>
                                        <span class="whitespace-nowrap font-medium tabular-nums text-gray-900 dark:text-white" dir="ltr">{{ $rate->rate }}</span>
                                    </x-ui.td>
                                    <x-ui.td>
                                        <span class="whitespace-nowrap text-gray-500 dark:text-gray-400">{{ $rate->effective_date?->format('Y-m-d') }}</span>
                                    </x-ui.td>
                                    <x-ui.td class="text-end">
                                        @if ($editable)
                                            <form method="POST" action="{{ route('settings.exchange-rates.destroy', $rate) }}">
                                                @csrf
                                                @method('DELETE')
                                                <x-ui.icon-button type="submit" variant="danger" size="sm" icon="trash" :label="__('actions.delete')" />
                                            </form>
                                        @endif
                                    </x-ui.td>
                                </tr>
                            @endforeach
                        </x-ui.table>
                    </div>
                @endif

                @if ($editable)
                    <form method="POST" action="{{ route('settings.exchange-rates.store') }}" class="mt-5 grid gap-x-6 gap-y-5 border-t border-gray-100 pt-5 sm:grid-cols-2 dark:border-gray-700">
                        @csrf
                        <div>
                            <x-ui.select name="currency_code" :label="__('settings.rate_currency')" :placeholder="__('settings.choose_currency')">
                                @foreach ($currency_enabled as $currency)
                                    @continue($currency->code === $currency_base)
                                    <option value="{{ $currency->code }}">
                                        {{ $currency->code }} — {{ $currency->name }}
                                    </option>
                                @endforeach
                            </x-ui.select>
                        </div>
                        <div>
                            <x-ui.number-input
                                name="rate"
                                :label="__('settings.rate')"
                                :helper="__('settings.rate_helper')"
                                min="0"
                                step="0.00000001"
                                inputmode="decimal"
                                required
                            />
                        </div>
                        <div>
                            <x-ui.input
                                name="effective_date"
                                type="date"
                                :label="__('settings.effective_date')"
                                :value="now()->toDateString()"
                                required
                            />
                        </div>
                        <div class="flex items-end">
                            <x-ui.button type="submit" icon="check-circle">{{ __('settings.save_rate') }}</x-ui.button>
                        </div>
                    </form>
                @endif
            </div>
        </x-ui.card>
    </x-app.page>
@endsection