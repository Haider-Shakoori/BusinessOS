@php
    $locale = app()->getLocale();
    $direction = App\Http\Middleware\SetLocale::direction($locale);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>

    {{-- Pre-paint theme (dark mode) --}}
    <script>
        (function () {
            var stored = localStorage.getItem('bos-theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored === 'dark' || (!stored && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    {{-- Pre-paint sidebar collapse state --}}
    <script>
        (function () {
            try {
                if (localStorage.getItem('bos-sidebar-collapsed') === '1') {
                    document.documentElement.classList.add('sidebar-collapsed');
                }
            } catch {
                // localStorage unavailable
            }
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full min-h-screen bg-gray-50 font-sans text-gray-900 antialiased dark:bg-gray-900 dark:text-gray-100">
    {{-- Skip link for accessibility --}}
    <a href="#main-content" class="sr-only focus:not-sr-only focus:absolute focus:start-4 focus:top-4 focus:z-[70] focus:rounded-lg focus:bg-brand-600 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white">
        {{ __('common.skip_to_content') }}
    </a>

    @php $navItems = App\Support\Navigation::items(); @endphp

    <div x-data="appLayout" class="min-h-screen">
        <x-app.sidebar :items="$navItems" />
        <x-app.mobile-nav :items="$navItems" />

        <div id="app-content" class="flex min-h-screen flex-col transition-[padding-inline-start] duration-200 ease-out lg:ps-64 sidebar-collapsed:lg:ps-16">
            <x-app.header />

            <main id="main-content" tabindex="-1" class="flex-1">
                {{-- Optional flash section --}}
                @hasSection('flash')
                    <div class="mx-auto w-full max-w-7xl px-4 pt-6 sm:px-6 lg:px-8">@yield('flash')</div>
                @endif

                <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    @yield('content')
                </div>
            </main>
        </div>
    </div>
</body>
</html>
