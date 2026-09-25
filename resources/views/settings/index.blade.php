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

        <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="space-y-6">
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

            <x-ui.card>
                <x-slot:header>
                    <div>
                        <h2 class="text-base font-semibold text-gray-900 dark:text-white">{{ __('settings.documents') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('settings.documents_helper') }}</p>
                    </div>
                </x-slot:header>

                <div class="grid gap-x-6 gap-y-5 sm:grid-cols-2">
                    <x-ui.select
                        name="document.invoice_theme"
                        :label="__('settings.invoice_theme')"
                        :value="$values['document.invoice_theme']"
                        :disabled="! $editable"
                    >
                        @foreach ($document_themes as $key => $theme)
                            <option value="{{ $key }}" @selected($values['document.invoice_theme'] === $key)>
                                {{ __($theme['label']) }}
                            </option>
                        @endforeach
                    </x-ui.select>

                    <x-ui.input
                        name="document.accent_color"
                        type="text"
                        :label="__('settings.accent_color')"
                        :helper="__('settings.accent_color_helper')"
                        :value="$values['document.accent_color']"
                        :disabled="! $editable"
                        maxlength="7"
                        pattern="^#[0-9A-Fa-f]{6}$"
                        dir="ltr"
                    />

                    <div class="sm:col-span-2">
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-200" for="document-logo">
                            {{ __('settings.document_logo') }}
                        </label>

                        @if ($document_logo_url)
                            <div class="mb-3 flex flex-wrap items-center gap-4 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800/60">
                                <img
                                    src="{{ $document_logo_url }}"
                                    alt="{{ __('settings.document_logo_current') }}"
                                    class="h-12 max-w-48 object-contain"
                                >
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">{{ __('settings.document_logo_current') }}</p>
                                    @if ($editable)
                                        <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                            <input type="hidden" name="document.remove_logo" value="0">
                                            <input
                                                type="checkbox"
                                                name="document.remove_logo"
                                                value="1"
                                                class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900"
                                            >
                                            {{ __('settings.remove_document_logo') }}
                                        </label>
                                    @endif
                                </div>
                            </div>
                        @endif

                        <input
                            id="document-logo"
                            type="file"
                            name="document.logo"
                            accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                            @disabled(! $editable)
                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 file:me-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-brand-700 hover:file:bg-brand-100 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:file:bg-brand-500/10 dark:file:text-brand-300"
                        >
                        <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">{{ __('settings.document_logo_helper') }}</p>
                        @error('document.logo')
                            <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <x-ui.textarea
                            name="document.header_text"
                            :label="__('settings.header_text')"
                            :helper="__('settings.header_text_helper')"
                            :value="$values['document.header_text'] ?: null"
                            rows="2"
                            :disabled="! $editable"
                            maxlength="500"
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <x-ui.textarea
                            name="document.terms"
                            :label="__('settings.terms_template')"
                            :helper="__('settings.terms_template_helper')"
                            :value="$values['document.terms'] ?: null"
                            rows="4"
                            :disabled="! $editable"
                            maxlength="4000"
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <x-ui.textarea
                            name="document.bank_details"
                            :label="__('settings.bank_details')"
                            :helper="__('settings.bank_details_helper')"
                            :value="$values['document.bank_details'] ?: null"
                            rows="4"
                            :disabled="! $editable"
                            maxlength="2000"
                        />
                    </div>

                    <x-ui.input
                        name="document.signature_line"
                        :label="__('settings.signature_line')"
                        :helper="__('settings.signature_line_helper')"
                        :value="$values['document.signature_line'] ?: null"
                        :disabled="! $editable"
                        maxlength="255"
                    />

                    <div class="sm:col-span-2">
                        <x-ui.textarea
                            name="document.footer_text"
                            :label="__('settings.footer_text')"
                            :helper="__('settings.footer_text_helper')"
                            :value="$values['document.footer_text'] ?: null"
                            rows="2"
                            :disabled="! $editable"
                            maxlength="500"
                        />
                    </div>
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