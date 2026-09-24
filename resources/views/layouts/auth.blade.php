@extends('layouts.minimal')

@section('content')
    <main class="flex min-h-screen items-center justify-center bg-gray-50 px-4 py-8 text-gray-900 dark:bg-gray-900 dark:text-gray-100">
        <div class="w-full max-w-md space-y-6">
            <div class="flex items-center justify-between gap-3">
                <span class="text-lg font-semibold">{{ config('app.name') }}</span>
                <div class="flex items-center gap-2">
                    <x-app.locale-switcher />
                    <x-ui.button variant="ghost" x-data="themeSwitcher" x-on:click="toggle" :aria-label="__('common.toggle_dark_mode')">
                        <x-ui.icon name="sun" class="size-5" x-show="dark" />
                        <x-ui.icon name="moon" class="size-5" x-show="!dark" />
                    </x-ui.button>
                </div>
            </div>
            <x-ui.card>
                <div class="space-y-6">
                    <div>
                        <h1 class="text-2xl font-semibold">@yield('title')</h1>
                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">@yield('description')</p>
                    </div>
                    @if (session('status'))
                        <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
                    @endif
                    @yield('form')
                </div>
            </x-ui.card>
        </div>
    </main>
@endsection
