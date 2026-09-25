@extends('layouts.minimal')

@section('content')
    <main class="relative flex min-h-screen items-center justify-center overflow-hidden bg-[#f5f7fb] px-4 py-10 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
        <div class="pointer-events-none absolute inset-x-0 top-0 h-64 bg-gradient-to-b from-brand-50/80 to-transparent dark:from-brand-950/20"></div>

        <div class="relative w-full max-w-[420px]">
            <div class="mb-5 flex items-center justify-between gap-3">
                <a href="{{ url('/') }}" class="flex min-w-0 items-center gap-2.5" aria-label="{{ config('app.name') }}">
                    <span class="relative block size-8 shrink-0" aria-hidden="true">
                        <span class="absolute start-0 top-0 h-[20px] w-[17px] rounded-[3px] bg-brand-500"></span>
                        <span class="absolute bottom-0 end-0 h-[20px] w-[17px] rounded-[3px] bg-brand-400/90"></span>
                        <span class="absolute start-[8px] top-[8px] size-[12px] rounded-[2px] bg-brand-600 shadow-sm"></span>
                    </span>
                    <span class="truncate text-[19px] font-semibold tracking-[-0.025em] text-slate-900 dark:text-white">
                        Business<span class="text-brand-600 dark:text-brand-400">OS</span>
                    </span>
                </a>

                <div class="flex items-center gap-1.5">
                    <x-app.locale-switcher />
                    <button
                        type="button"
                        x-data="themeSwitcher"
                        x-on:click="toggle"
                        class="grid size-9 place-items-center rounded-[7px] text-slate-500 transition-colors hover:bg-white hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-white"
                        aria-label="{{ __('common.toggle_dark_mode') }}"
                    >
                        <x-ui.icon name="sun" class="size-4.5" x-show="dark" />
                        <x-ui.icon name="moon" class="size-4.5" x-show="!dark" />
                    </button>
                </div>
            </div>

            <div class="overflow-hidden rounded-[12px] border border-slate-200/90 bg-white shadow-[0_12px_38px_rgba(15,23,42,0.08)] dark:border-slate-700 dark:bg-slate-900">
                <div class="h-1 bg-brand-600"></div>
                <div class="p-6 sm:p-7">
                    <div class="mb-6">
                        <h1 class="text-[22px] font-bold leading-tight tracking-[-0.02em] text-slate-900 dark:text-white">@yield('title')</h1>
                        <p class="mt-1.5 text-[12px] leading-5 text-slate-500 dark:text-slate-400">@yield('description')</p>
                    </div>

                    @if (session('status'))
                        <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div>
                    @endif

                    @yield('form')
                </div>
            </div>

            <p class="mt-4 text-center text-[11px] text-slate-400 dark:text-slate-600">
                {{ __('common.brand_tagline') }}
            </p>
        </div>
    </main>
@endsection
