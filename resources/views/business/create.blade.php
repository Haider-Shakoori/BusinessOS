@extends('layouts.app')

@section('content')
@php
    $selectedModules = old('modules', array_keys($onboardingModules));
    $defaultLocale = old('locale', $defaults['locale'] ?? app()->getLocale());
    $defaultAppearance = old('appearance', $defaults['appearance'] ?? 'light');
    $defaultCountry = old('country', $defaults['country'] ?? 'AF');
    $defaultCurrency = old('currency', $defaults['currency'] ?? 'AFN');
    $defaultTimezone = old('timezone', $defaults['timezone'] ?? 'Asia/Kabul');
@endphp

<div
    x-data="{
        step: {{ $errors->any() ? 2 : 1 }},
        name: @js(old('name', '')),
        industry: @js(old('industry', 'retail_wholesale')),
        country: @js($defaultCountry),
        currency: @js($defaultCurrency),
        timezone: @js($defaultTimezone),
        address: @js(old('address', '')),
        phone: @js(old('phone', '')),
        email: @js(old('email', auth()->user()?->email ?? '')),
        locale: @js($defaultLocale),
        appearance: @js($defaultAppearance),
        taxEnabled: @js((bool) old('tax_enabled', false)),
        selectedModules: @js(array_values($selectedModules)),
        logoPreview: null,
        moduleCount() { return this.selectedModules.length; },
        next(step) { this.step = step; window.scrollTo({ top: 0, behavior: 'smooth' }); },
        applyAppearance() {
            if (this.appearance === 'dark') {
                document.documentElement.classList.add('dark');
                localStorage.setItem('bos-theme', 'dark');
            } else if (this.appearance === 'light') {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('bos-theme', 'light');
            } else {
                localStorage.removeItem('bos-theme');
            }
        },
        previewLogo(event) {
            const file = event.target.files?.[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = e => this.logoPreview = e.target.result;
            reader.readAsDataURL(file);
        }
    }"
    x-on:submit="applyAppearance()"
    class="mx-auto w-full max-w-[1320px]"
>
    <div class="mb-4 flex items-start justify-between gap-4">
        <div>
            <template x-if="step === 1">
                <div>
                    <h1 class="text-[26px] font-bold tracking-[-0.03em] text-slate-950 dark:text-white">{{ __('onboarding.welcome_title') }}</h1>
                    <p class="mt-1 text-[13px] text-slate-500 dark:text-slate-400">{{ __('onboarding.welcome_subtitle') }}</p>
                </div>
            </template>
            <template x-if="step === 2">
                <div>
                    <p class="text-[11px] font-medium text-slate-500 dark:text-slate-400">{{ __('onboarding.business_kicker') }}</p>
                    <h1 class="mt-0.5 text-[28px] font-bold tracking-[-0.035em] text-slate-950 dark:text-white">{{ __('onboarding.business_title') }}</h1>
                    <p class="text-[13px] text-slate-500 dark:text-slate-400">{{ __('onboarding.business_subtitle') }}</p>
                </div>
            </template>
            <template x-if="step >= 3">
                <div>
                    <h1 class="text-[28px] font-bold tracking-[-0.035em] text-slate-950 dark:text-white">{{ __('onboarding.preferences_title') }}</h1>
                    <p class="mt-0.5 text-[13px] text-slate-500 dark:text-slate-400">{{ __('onboarding.preferences_subtitle') }}</p>
                </div>
            </template>
        </div>
        <div class="shrink-0 text-end">
            <p class="text-[11px] font-semibold text-slate-800 dark:text-slate-200" x-text="@js(__('onboarding.step_of', ['step' => '__STEP__'])).replace('__STEP__', step)"></p>
            <p class="mt-1 hidden text-[10px] text-slate-500 sm:block dark:text-slate-400" x-text="step === 3 ? @js(__('onboarding.almost_there')) : @js(__('onboarding.few_minutes'))"></p>
        </div>
    </div>

    <form method="POST" action="{{ route('business.store') }}" enctype="multipart/form-data">
        @csrf

        <div class="overflow-hidden rounded-[12px] border border-slate-200 bg-white shadow-[0_12px_40px_rgba(15,23,42,0.06)] dark:border-slate-700 dark:bg-slate-900">
            <div class="grid grid-cols-4 gap-0 border-b border-slate-100 px-6 py-5 dark:border-slate-800 sm:px-10">
                @foreach ([
                    1 => ['label' => __('onboarding.steps.welcome'), 'help' => __('onboarding.steps.welcome_help')],
                    2 => ['label' => __('onboarding.steps.business'), 'help' => __('onboarding.steps.business_help')],
                    3 => ['label' => __('onboarding.steps.preferences'), 'help' => __('onboarding.steps.preferences_help')],
                    4 => ['label' => __('onboarding.steps.complete'), 'help' => __('onboarding.steps.complete_help')],
                ] as $number => $meta)
                    <div class="relative text-center">
                        @if ($number < 4)
                            <span class="absolute start-1/2 top-4 h-[2px] w-full bg-slate-200 dark:bg-slate-700" :class="step > {{ $number }} ? '!bg-brand-500' : ''"></span>
                        @endif
                        <span class="relative z-10 mx-auto grid size-8 place-items-center rounded-full border-2 text-[12px] font-bold"
                              :class="step >= {{ $number }} ? 'border-brand-500 bg-brand-500 text-white shadow-[0_0_0_3px_rgba(47,140,255,.12)]' : 'border-slate-200 bg-white text-slate-500 dark:border-slate-700 dark:bg-slate-900'">
                            <template x-if="step > {{ $number }}"><x-ui.icon name="check" class="size-4" /></template>
                            <template x-if="step <= {{ $number }}"><span>{{ $number }}</span></template>
                        </span>
                        <p class="relative z-10 mt-1.5 text-[11px] font-semibold text-slate-800 dark:text-slate-200">{{ $meta['label'] }}</p>
                        <p class="relative z-10 mt-0.5 hidden text-[9px] text-slate-500 md:block dark:text-slate-400">{{ $meta['help'] }}</p>
                    </div>
                @endforeach
            </div>

            <section x-show="step === 1" x-cloak>
                <div class="grid lg:grid-cols-[1.05fr_.95fr]">
                    <div class="px-7 py-8 sm:px-10 sm:py-10">
                        <h2 class="text-[26px] font-bold tracking-[-0.035em] text-slate-950 dark:text-white">{{ __('onboarding.welcome_hero') }}</h2>
                        <p class="mt-2 text-[16px] font-medium text-slate-700 dark:text-slate-200">{{ __('onboarding.welcome_tagline') }}</p>
                        <p class="mt-4 max-w-2xl text-[13px] leading-6 text-slate-500 dark:text-slate-400">{{ __('onboarding.welcome_description') }}</p>

                        <div class="mt-8 grid gap-4 sm:grid-cols-2">
                            <label class="block">
                                <span class="mb-2 flex items-center gap-2 text-[11px] font-semibold text-slate-700 dark:text-slate-200">
                                    <x-ui.icon name="globe" class="size-4 text-brand-600" /> {{ __('onboarding.select_language') }}
                                </span>
                                <select name="locale" x-model="locale" class="h-10 w-full rounded-[7px] border border-slate-200 bg-white px-3 text-[12px] font-medium text-slate-700 shadow-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                    @foreach ($supportedLocales as $code => $localeMeta)
                                        <option value="{{ $code }}">{{ $localeMeta['native'] }}</option>
                                    @endforeach
                                </select>
                                <span class="mt-1 block text-[9px] text-slate-400">{{ __('onboarding.change_anytime') }}</span>
                            </label>
                            <label class="block">
                                <span class="mb-2 flex items-center gap-2 text-[11px] font-semibold text-slate-700 dark:text-slate-200">
                                    <x-ui.icon name="sun" class="size-4 text-brand-600" /> {{ __('onboarding.choose_appearance') }}
                                </span>
                                <select name="appearance" x-model="appearance" x-on:change="applyAppearance()" class="h-10 w-full rounded-[7px] border border-slate-200 bg-white px-3 text-[12px] font-medium text-slate-700 shadow-sm focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100">
                                    <option value="light">{{ __('onboarding.light_default') }}</option>
                                    <option value="dark">{{ __('onboarding.dark') }}</option>
                                    <option value="system">{{ __('onboarding.system') }}</option>
                                </select>
                                <span class="mt-1 block text-[9px] text-slate-400">{{ __('onboarding.appearance_help') }}</span>
                            </label>
                        </div>

                        <div class="mt-8 grid gap-3 sm:grid-cols-2">
                            <button type="button" x-on:click="next(2)" class="inline-flex h-11 items-center justify-center gap-3 rounded-[7px] bg-brand-600 px-5 text-sm font-semibold text-white shadow-[0_6px_18px_rgba(20,115,230,.25)] hover:bg-brand-700">
                                {{ __('onboarding.start_setup') }} <x-ui.icon name="arrow-right" class="size-4 rtl-flip" />
                            </button>
                            <button type="button" x-on:click="next(2)" class="inline-flex h-11 items-center justify-center rounded-[7px] border border-slate-200 bg-white px-5 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                                {{ __('onboarding.skip_for_now') }}
                            </button>
                        </div>
                        <p class="mt-4 flex items-center gap-2 text-[10px] text-slate-500 dark:text-slate-400">
                            <x-ui.icon name="info-circle" class="size-4 text-brand-500" /> {{ __('onboarding.complete_later') }}
                        </p>
                    </div>

                    <aside class="relative overflow-hidden border-t border-slate-100 bg-[linear-gradient(145deg,#f4f8ff_0%,#edf5ff_50%,#ffffff_100%)] px-7 py-10 dark:border-slate-800 dark:bg-slate-900 lg:border-s lg:border-t-0">
                        <div class="absolute -end-16 top-16 size-48 rounded-full bg-brand-200/30 blur-3xl"></div>
                        <div class="relative">
                            <h3 class="max-w-md text-[24px] font-bold leading-tight tracking-[-0.03em] text-slate-950 dark:text-white">
                                {{ __('onboarding.run_smarter') }}
                            </h3>
                            <p class="mt-3 max-w-md text-[13px] leading-5 text-slate-500 dark:text-slate-400">{{ __('onboarding.run_smarter_help') }}</p>

                            <div class="mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                                @foreach ([
                                    ['users','bg-emerald-50 text-emerald-600','onboarding.benefits.customers'],
                                    ['briefcase','bg-blue-50 text-blue-600','onboarding.benefits.sales'],
                                    ['archive-box','bg-orange-50 text-orange-600','onboarding.benefits.inventory'],
                                    ['chart-bar','bg-violet-50 text-violet-600','onboarding.benefits.growth'],
                                ] as [$icon,$tone,$key])
                                    <div class="flex items-center gap-3 rounded-[9px] border border-white/80 bg-white/80 px-3 py-2.5 shadow-sm backdrop-blur dark:border-slate-700 dark:bg-slate-800/80">
                                        <span class="grid size-9 place-items-center rounded-[8px] {{ $tone }}"><x-ui.icon :name="$icon" class="size-4.5" /></span>
                                        <span class="text-[11px] font-semibold text-slate-700 dark:text-slate-200">{{ __($key) }}</span>
                                    </div>
                                @endforeach
                            </div>

                            <div class="mt-8 rounded-[10px] border border-white/80 bg-white/75 p-4 text-[10px] leading-5 text-slate-500 shadow-sm backdrop-blur dark:border-slate-700 dark:bg-slate-800/80 dark:text-slate-400">
                                “{{ __('onboarding.testimonial') }}”
                                <p class="mt-2 font-medium text-slate-600 dark:text-slate-300">— {{ __('onboarding.testimonial_by') }}</p>
                            </div>
                        </div>
                    </aside>
                </div>
            </section>

            <section x-show="step === 2" x-cloak>
                <div class="grid lg:grid-cols-[1.05fr_.95fr]">
                    <div class="px-7 py-7 sm:px-10">
                        <div>
                            <h2 class="text-[17px] font-bold text-slate-950 dark:text-white">{{ __('onboarding.company_details') }}</h2>
                            <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{{ __('onboarding.company_details_help') }}</p>
                        </div>

                        <div class="mt-4 flex items-center gap-4">
                            <label class="grid size-20 shrink-0 cursor-pointer place-items-center overflow-hidden rounded-[9px] border border-dashed border-slate-300 bg-slate-50 text-slate-400 hover:border-brand-400 dark:border-slate-700 dark:bg-slate-800">
                                <template x-if="logoPreview"><img :src="logoPreview" class="size-full object-cover" alt=""></template>
                                <template x-if="!logoPreview"><x-ui.icon name="building-office" class="size-8" /></template>
                                <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" class="sr-only" x-on:change="previewLogo">
                            </label>
                            <div>
                                <p class="text-[11px] font-semibold text-slate-800 dark:text-slate-200">{{ __('onboarding.company_logo') }}</p>
                                <p class="mt-1 max-w-sm text-[9px] leading-4 text-slate-500 dark:text-slate-400">{{ __('onboarding.company_logo_help') }}</p>
                                <label class="mt-2 inline-flex cursor-pointer items-center gap-2 rounded-[6px] bg-brand-50 px-3 py-1.5 text-[10px] font-semibold text-brand-700 hover:bg-brand-100 dark:bg-brand-500/10 dark:text-brand-300">
                                    <x-ui.icon name="arrow-up-tray" class="size-3.5" /> {{ __('onboarding.upload_logo') }}
                                    <input type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" class="sr-only" x-on:change="previewLogo">
                                </label>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-x-5 gap-y-4 sm:grid-cols-2">
                            <label><span class="mb-1.5 block text-[10px] font-semibold">{{ __('onboarding.business_name') }} <span class="text-rose-500">*</span></span><input x-ref="name" x-model="name" name="name" value="{{ old('name') }}" required maxlength="255" class="h-9 w-full rounded-[6px] border border-slate-200 px-3 text-[11px] focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-900"></label>
                            <label><span class="mb-1.5 block text-[10px] font-semibold">{{ __('onboarding.industry') }} <span class="text-rose-500">*</span></span><select x-model="industry" name="industry" class="h-9 w-full rounded-[6px] border border-slate-200 px-3 text-[11px] dark:border-slate-700 dark:bg-slate-900">@foreach($industries as $key=>$label)<option value="{{ $key }}">{{ __($label) }}</option>@endforeach</select></label>
                            <label><span class="mb-1.5 block text-[10px] font-semibold">{{ __('onboarding.country_region') }} <span class="text-rose-500">*</span></span><select x-model="country" name="country" required class="h-9 w-full rounded-[6px] border border-slate-200 px-3 text-[11px] dark:border-slate-700 dark:bg-slate-900">@foreach($countries as $key=>$label)<option value="{{ $key }}">{{ __($label) }}</option>@endforeach</select></label>
                            <label><span class="mb-1.5 block text-[10px] font-semibold">{{ __('onboarding.base_currency') }} <span class="text-rose-500">*</span></span><select x-model="currency" name="currency" required class="h-9 w-full rounded-[6px] border border-slate-200 px-3 text-[11px] dark:border-slate-700 dark:bg-slate-900">@foreach($currencies as $currencyOption)<option value="{{ $currencyOption->code }}">{{ $currencyOption->code }} — {{ $currencyOption->name }}</option>@endforeach</select></label>
                            <label><span class="mb-1.5 block text-[10px] font-semibold">{{ __('onboarding.time_zone') }} <span class="text-rose-500">*</span></span><select x-model="timezone" name="timezone" required class="h-9 w-full rounded-[6px] border border-slate-200 px-3 text-[11px] dark:border-slate-700 dark:bg-slate-900">@foreach($timezones as $key=>$label)<option value="{{ $key }}">{{ __($label) }}</option>@endforeach</select></label>
                            <label><span class="mb-1.5 block text-[10px] font-semibold">{{ __('onboarding.business_address') }}</span><input x-model="address" name="address" value="{{ old('address') }}" maxlength="1000" class="h-9 w-full rounded-[6px] border border-slate-200 px-3 text-[11px] focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-900"></label>
                            <label><span class="mb-1.5 block text-[10px] font-semibold">{{ __('onboarding.phone_number') }}</span><input x-model="phone" name="phone" value="{{ old('phone') }}" maxlength="100" class="h-9 w-full rounded-[6px] border border-slate-200 px-3 text-[11px] focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-900"></label>
                            <label><span class="mb-1.5 block text-[10px] font-semibold">{{ __('onboarding.email_address') }}</span><input x-model="email" name="email" value="{{ old('email', auth()->user()?->email) }}" type="email" maxlength="255" class="h-9 w-full rounded-[6px] border border-slate-200 px-3 text-[11px] focus:border-brand-500 focus:outline-none dark:border-slate-700 dark:bg-slate-900"></label>
                        </div>

                        @if ($errors->any())
                            <div class="mt-4 rounded-[8px] border border-rose-200 bg-rose-50 px-4 py-3 text-[11px] text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300">
                                <ul class="list-disc space-y-1 ps-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                            </div>
                        @endif

                        <p class="mt-4 flex items-center gap-2 rounded-[6px] bg-blue-50 px-3 py-2 text-[9px] text-blue-700 dark:bg-blue-500/10 dark:text-blue-300"><x-ui.icon name="info-circle" class="size-3.5" /> {{ __('onboarding.edit_later') }}</p>

                        <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                            <button type="button" x-on:click="next(1)" class="inline-flex h-9 items-center gap-2 rounded-[6px] border border-slate-200 px-5 text-[11px] font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200"><x-ui.icon name="chevron-left" class="size-3.5 rtl-flip" /> {{ __('onboarding.back') }}</button>
                            <div class="ms-auto flex flex-wrap gap-2">
                                <button type="button" x-on:click="next(3)" class="h-9 rounded-[6px] border border-slate-200 px-5 text-[11px] font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200">{{ __('onboarding.save_continue_later') }}</button>
                                <button type="button" x-on:click="next(3)" class="inline-flex h-9 items-center gap-2 rounded-[6px] bg-brand-600 px-5 text-[11px] font-semibold text-white">{{ __('onboarding.continue') }} <x-ui.icon name="arrow-right" class="size-3.5 rtl-flip" /></button>
                            </div>
                        </div>
                    </div>

                    <aside class="border-t border-slate-100 bg-[linear-gradient(145deg,#f4f8ff_0%,#edf5ff_60%,#ffffff_100%)] px-8 py-9 dark:border-slate-800 dark:bg-slate-900 lg:border-s lg:border-t-0">
                        <h3 class="text-[24px] font-bold leading-tight tracking-[-0.03em] text-slate-950 dark:text-white">{{ __('onboarding.foundation_title') }}</h3>
                        <p class="mt-2 max-w-md text-[12px] leading-5 text-slate-500 dark:text-slate-400">{{ __('onboarding.foundation_help') }}</p>

                        <div class="mt-6 space-y-4">
                            @foreach ([
                                ['cog','emerald','personalized'],
                                ['users','violet','recommendations'],
                                ['globe','orange','localized'],
                                ['bolt','blue','faster'],
                            ] as [$icon,$tone,$key])
                                <div class="flex gap-3">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-[8px] bg-white/90 text-brand-600 shadow-sm dark:bg-slate-800"><x-ui.icon :name="$icon" class="size-4.5" /></span>
                                    <div><p class="text-[11px] font-semibold text-slate-800 dark:text-slate-200">{{ __("onboarding.foundation_items.$key") }}</p><p class="mt-0.5 text-[9px] text-slate-500 dark:text-slate-400">{{ __("onboarding.foundation_items.{$key}_help") }}</p></div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-8 rounded-[10px] border border-white/80 bg-white/80 p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800/80">
                            <div class="flex items-center justify-between"><p class="text-[11px] font-semibold">{{ __('onboarding.your_company') }}</p><span class="rounded-full bg-blue-50 px-2 py-1 text-[8px] font-semibold text-blue-600">{{ __('onboarding.example') }}</span></div>
                            <p class="mt-1 text-[9px] text-slate-500">{{ __('onboarding.company_preview_help') }}</p>
                            <div class="mt-4 flex gap-3">
                                <span class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-[8px] bg-slate-100 text-slate-400 dark:bg-slate-700">
                                    <template x-if="logoPreview"><img :src="logoPreview" class="size-full object-cover" alt=""></template>
                                    <template x-if="!logoPreview"><x-ui.icon name="building-office" class="size-7" /></template>
                                </span>
                                <div class="min-w-0">
                                    <p class="truncate text-[12px] font-bold text-slate-900 dark:text-white" x-text="name || @js(__('onboarding.your_company'))"></p>
                                    <p class="mt-1 text-[9px] text-slate-500" x-text="address || @js(__('onboarding.countries.afghanistan'))"></p>
                                    <p class="mt-1 text-[9px] text-slate-500" x-text="currency"></p>
                                    <p class="mt-1 text-[9px] text-slate-500" x-text="phone || '—'"></p>
                                    <p class="mt-1 truncate text-[9px] text-slate-500" x-text="email"></p>
                                </div>
                            </div>
                        </div>
                    </aside>
                </div>
            </section>

            <section x-show="step === 3" x-cloak>
                <div class="grid lg:grid-cols-[minmax(0,1fr)_330px]">
                    <div class="px-5 py-5 sm:px-7">
                        <div class="rounded-[9px] border border-slate-200 p-4 dark:border-slate-700">
                            <div class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-[7px] bg-brand-50 text-brand-600"><x-ui.icon name="squares-2x2" class="size-4" /></span><div><h2 class="text-[13px] font-bold">{{ __('onboarding.enable_modules') }}</h2><p class="text-[9px] text-slate-500">{{ __('onboarding.enable_modules_help') }}</p></div></div>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                @foreach($onboardingModules as $key=>$module)
                                    <label class="flex min-h-[78px] cursor-pointer items-start gap-3 rounded-[8px] border border-slate-200 p-3 hover:border-brand-300 dark:border-slate-700">
                                        <span class="grid size-9 shrink-0 place-items-center rounded-[8px] bg-brand-50 text-brand-600 dark:bg-brand-500/10"><x-ui.icon :name="$module['icon']" class="size-4.5" /></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="flex items-center justify-between gap-2"><span class="text-[10px] font-bold">{{ __($module['label']) }}</span><input type="checkbox" name="modules[]" value="{{ $key }}" x-model="selectedModules" class="size-4 rounded-full border-slate-300 text-brand-600 focus:ring-brand-500"></span>
                                            <span class="mt-1 block text-[8px] leading-3 text-slate-500">{{ __($module['description']) }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="mt-3 rounded-[9px] border border-slate-200 p-4 dark:border-slate-700">
                            <div class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-[7px] bg-brand-50 text-brand-600"><x-ui.icon name="cog" class="size-4" /></span><div><h2 class="text-[13px] font-bold">{{ __('onboarding.workspace_preferences') }}</h2><p class="text-[9px] text-slate-500">{{ __('onboarding.workspace_preferences_help') }}</p></div></div>
                            <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                <label><span class="mb-1.5 block text-[9px] font-semibold">{{ __('onboarding.language') }}</span><select name="locale" x-model="locale" class="h-9 w-full rounded-[6px] border border-slate-200 px-2 text-[10px] dark:border-slate-700 dark:bg-slate-900">@foreach($supportedLocales as $code=>$meta)<option value="{{ $code }}">{{ $meta['native'] }}</option>@endforeach</select></label>
                                <label><span class="mb-1.5 block text-[9px] font-semibold">{{ __('onboarding.appearance') }}</span><select name="appearance" x-model="appearance" x-on:change="applyAppearance()" class="h-9 w-full rounded-[6px] border border-slate-200 px-2 text-[10px] dark:border-slate-700 dark:bg-slate-900"><option value="light">{{ __('onboarding.light_default') }}</option><option value="dark">{{ __('onboarding.dark') }}</option><option value="system">{{ __('onboarding.system') }}</option></select></label>
                                <label class="block"><span class="mb-1.5 block text-[9px] font-semibold">{{ __('onboarding.enable_tax') }}</span><span class="flex h-9 items-center justify-between rounded-[6px] border border-slate-200 px-3 dark:border-slate-700"><span class="text-[8px] text-slate-500">{{ __('onboarding.enable_tax_help') }}</span><input type="hidden" name="tax_enabled" value="0"><input type="checkbox" name="tax_enabled" value="1" x-model="taxEnabled" class="size-4 rounded-full border-slate-300 text-brand-600"></span></label>
                                <label><span class="mb-1.5 block text-[9px] font-semibold">{{ __('onboarding.default_invoice_number') }}</span><input value="{{ $defaults['invoice_preview'] ?? 'INV-000001' }}" readonly class="h-9 w-full rounded-[6px] border border-slate-200 bg-slate-50 px-3 text-[10px] font-semibold dark:border-slate-700 dark:bg-slate-800"><span class="mt-1 block text-[8px] text-slate-400">{{ __('onboarding.invoice_number_help') }}</span></label>
                            </div>
                        </div>

                        <div class="mt-3 rounded-[9px] border border-slate-200 p-4 dark:border-slate-700">
                            <div class="flex items-center gap-3"><span class="grid size-8 place-items-center rounded-[7px] bg-brand-50 text-brand-600"><x-ui.icon name="bolt" class="size-4" /></span><div><h2 class="text-[13px] font-bold">{{ __('onboarding.quick_start') }}</h2><p class="text-[9px] text-slate-500">{{ __('onboarding.quick_start_help') }}</p></div></div>
                            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                @foreach([['users','customers'],['cube','products'],['document-text','invoice'],['chart-bar','reports']] as [$icon,$key])
                                    <div class="flex gap-2 rounded-[8px] border border-slate-200 p-3 dark:border-slate-700"><span class="grid size-8 shrink-0 place-items-center rounded-[7px] bg-brand-50 text-brand-600"><x-ui.icon :name="$icon" class="size-4" /></span><div><p class="text-[9px] font-bold">{{ __("onboarding.quick_tips.$key") }}</p><p class="mt-1 text-[8px] leading-3 text-slate-500">{{ __("onboarding.quick_tips.{$key}_help") }}</p></div></div>
                                @endforeach
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="button" x-on:click="next(2)" class="inline-flex h-9 items-center gap-2 rounded-[6px] border border-slate-200 px-5 text-[10px] font-semibold text-slate-700 dark:border-slate-700 dark:text-slate-200"><x-ui.icon name="chevron-left" class="size-3.5 rtl-flip" /> {{ __('onboarding.back') }}</button>
                        </div>
                    </div>

                    <aside class="border-t border-slate-100 bg-[linear-gradient(160deg,#f6f9ff,#edf5ff_55%,#ffffff)] p-5 dark:border-slate-800 dark:bg-slate-900 lg:border-s lg:border-t-0">
                        <div class="text-center">
                            <span class="mx-auto grid size-16 place-items-center rounded-full bg-brand-500 text-white shadow-[0_8px_30px_rgba(47,140,255,.28)]"><x-ui.icon name="check" class="size-8" /></span>
                            <h3 class="mt-4 text-[20px] font-bold tracking-[-0.025em] text-slate-950 dark:text-white">{{ __('onboarding.workspace_ready') }}</h3>
                            <p class="mt-2 text-[10px] leading-4 text-slate-500">{{ __('onboarding.workspace_ready_help') }}</p>
                        </div>

                        <div class="mt-5 rounded-[9px] border border-white/80 bg-white/85 p-4 shadow-sm dark:border-slate-700 dark:bg-slate-800/85">
                            <h4 class="flex items-center gap-2 text-[11px] font-bold"><x-ui.icon name="building-office" class="size-4 text-brand-600" /> {{ __('onboarding.configuration_summary') }}</h4>
                            <dl class="mt-3 space-y-2.5 text-[9px]">
                                <div class="flex justify-between gap-3"><dt class="flex items-center gap-2"><x-ui.icon name="check-circle" class="size-3.5 text-emerald-500" />{{ __('onboarding.company_information_added') }}</dt><dd class="max-w-[120px] truncate text-slate-500" x-text="name || @js(__('onboarding.your_company'))"></dd></div>
                                <div class="flex justify-between gap-3"><dt class="flex items-center gap-2"><x-ui.icon name="check-circle" class="size-3.5 text-emerald-500" />{{ __('onboarding.modules_configured') }}</dt><dd class="text-slate-500" x-text="@js(__('onboarding.modules_enabled', ['count'=>'__COUNT__'])).replace('__COUNT__', moduleCount())"></dd></div>
                                <div class="flex justify-between gap-3"><dt class="flex items-center gap-2"><x-ui.icon name="check-circle" class="size-3.5 text-emerald-500" />{{ __('onboarding.language') }}</dt><dd class="text-slate-500" x-text="locale.toUpperCase()"></dd></div>
                                <div class="flex justify-between gap-3"><dt class="flex items-center gap-2"><x-ui.icon name="check-circle" class="size-3.5 text-emerald-500" />{{ __('onboarding.appearance_summary') }}</dt><dd class="text-slate-500" x-text="appearance"></dd></div>
                                <div class="flex justify-between gap-3"><dt class="flex items-center gap-2"><x-ui.icon name="check-circle" class="size-3.5 text-emerald-500" />{{ __('onboarding.tax_summary') }}</dt><dd class="text-slate-500" x-text="taxEnabled ? @js(__('onboarding.enabled')) : @js(__('onboarding.disabled'))"></dd></div>
                                <div class="flex justify-between gap-3"><dt class="flex items-center gap-2"><x-ui.icon name="check-circle" class="size-3.5 text-emerald-500" />{{ __('onboarding.invoice_numbering') }}</dt><dd class="text-slate-500">{{ $defaults['invoice_preview'] ?? 'INV-000001' }}</dd></div>
                            </dl>
                        </div>

                        <button type="submit" class="mt-4 inline-flex h-10 w-full items-center justify-center gap-3 rounded-[7px] bg-brand-600 px-4 text-[11px] font-semibold text-white shadow-[0_6px_18px_rgba(20,115,230,.25)] hover:bg-brand-700">
                            {{ __('onboarding.go_dashboard') }} <x-ui.icon name="arrow-right" class="size-4 rtl-flip" />
                        </button>
                        <button type="submit" class="mt-2 w-full py-2 text-[9px] font-semibold text-brand-600">{{ __('onboarding.do_later') }}</button>

                        <div class="mt-5 rounded-[9px] border border-white/80 bg-white/70 p-4 text-[9px] leading-4 text-slate-500 shadow-sm dark:border-slate-700 dark:bg-slate-800/70">
                            “{{ __('onboarding.ready_quote') }}”
                            <p class="mt-2 font-medium text-slate-600">— {{ __('onboarding.ready_quote_by') }}</p>
                        </div>
                    </aside>
                </div>
            </section>
        </div>
    </form>
</div>
@endsection
