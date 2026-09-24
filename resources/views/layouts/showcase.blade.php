<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>BusinessOS — UI Preview</title>
    <meta name="robots" content="noindex, nofollow">
    <script>
        (function () {
            var stored = localStorage.getItem('bos-theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored === 'dark' || (!stored && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 font-sans text-gray-900 antialiased dark:bg-gray-900 dark:text-gray-100">
    <header class="sticky top-0 z-40 border-b border-gray-200 bg-white/80 backdrop-blur-md dark:border-gray-700 dark:bg-gray-800/80">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
            <div class="flex items-center gap-3">
                <div class="grid size-8 place-items-center rounded-lg bg-brand-600 text-white">
                    <x-ui.icon name="sparkles" class="size-4" />
                </div>
                <div class="leading-tight">
                    <p class="text-sm font-semibold text-gray-900 dark:text-white">BusinessOS</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Component Showcase</p>
                </div>
                <x-ui.badge tone="warning" class="ms-1">Development only</x-ui.badge>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    x-data="themeSwitcher"
                    x-on:click="toggle"
                    class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:hover:bg-gray-700"
                    aria-label="Toggle dark mode"
                >
                    <template x-if="dark">
                        <x-ui.icon name="sun" class="size-4" />
                    </template>
                    <template x-if="!dark">
                        <x-ui.icon name="moon" class="size-4" />
                    </template>
                    <span x-text="dark ? 'Light' : 'Dark'"></span>
                </button>
                <a href="/" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-gray-500 transition-colors hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                    Back home
                </a>
            </div>
        </div>
    </header>

    <main class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:py-10">
        @yield('content')
    </main>

    <footer class="mx-auto max-w-7xl px-4 pb-10 sm:px-6">
        <p class="border-t border-gray-200 pt-6 text-xs text-gray-400 dark:border-gray-700 dark:text-gray-500">
            Batch 2 design-system preview. This route is <code class="font-mono font-medium">local</code>-only and removed in production builds.
        </p>
    </footer>
</body>
</html>