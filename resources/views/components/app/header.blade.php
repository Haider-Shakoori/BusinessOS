<header class="app-header sticky top-0 z-20 flex h-16 items-center gap-2 border-b border-gray-200 bg-white/80 px-4 backdrop-blur-md sm:px-6 dark:border-gray-700 dark:bg-gray-800/80">
    {{-- Mobile menu trigger --}}
    <button
        type="button"
        x-on:click="$dispatch('bos:open-drawer', { id: 'app-mobile-nav' })"
        class="lg:hidden inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
        aria-controls="app-mobile-nav"
        aria-label="{{ __('common.open_navigation') }}"
        aria-haspopup="dialog"
    >
        <x-ui.icon name="menu" class="size-5" aria-hidden="true" />
    </button>

    {{-- Search placeholder (desktop/tablet) --}}
    <div class="ms-1 hidden md:block flex-1 max-w-md">
        <button
            type="button"
            class="group flex w-full items-center gap-2.5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-500 transition-colors hover:border-gray-300 hover:bg-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:border-gray-700 dark:bg-gray-800/60 dark:text-gray-400 dark:hover:border-gray-600 dark:hover:bg-gray-800"
            aria-label="{{ __('common.search_coming_soon') }}"
            title="{{ __('common.search_coming_soon') }}"
        >
            <x-ui.icon name="search" class="size-4" aria-hidden="true" />
            <span class="truncate">{{ __('common.search') }}</span>
            <kbd class="ms-auto hidden rounded bg-gray-100 px-1.5 py-0.5 text-[11px] font-medium text-gray-400 sm:inline dark:bg-gray-700 dark:text-gray-500">⌘K</kbd>
        </button>
    </div>

    {{-- Search icon-only for small screens --}}
    <div class="md:hidden ms-auto inline-flex">
        <button
            type="button"
            class="inline-flex size-9 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            aria-label="{{ __('common.search_coming_soon') }}"
            title="{{ __('common.search_coming_soon') }}"
        >
            <x-ui.icon name="search" class="size-5" aria-hidden="true" />
        </button>
    </div>

    <div class="ms-auto flex items-center gap-1.5 sm:gap-2">
        {{-- Quick action placeholder --}}
        <button
            type="button"
            class="hidden md:inline-flex size-9 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            aria-label="{{ __('common.quick_actions_coming_soon') }}"
            title="{{ __('common.quick_actions_coming_soon') }}"
        >
            <x-ui.icon name="plus" class="size-5" aria-hidden="true" />
        </button>

        {{-- Notifications placeholder --}}
        <span class="hidden relative md:inline-flex">
            <button
                type="button"
                class="inline-flex size-9 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                aria-label="{{ __('common.notifications_coming_soon') }}"
                title="{{ __('common.notifications_coming_soon') }}"
            >
                <x-ui.icon name="bell" class="size-5" aria-hidden="true" />
                <span class="absolute -end-0.5 -top-0.5 size-2 rounded-full bg-red-500 ring-2 ring-white dark:ring-gray-800" aria-hidden="true"></span>
            </button>
        </span>

        {{-- Business switcher --}}
        <x-app.business-switcher />

        {{-- Locale switcher --}}
        <x-app.locale-switcher />

        {{-- Theme toggle --}}
        <button
            type="button"
            x-data="themeSwitcher"
            x-on:click="toggle"
            class="inline-flex size-9 items-center justify-center rounded-lg text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-gray-700 dark:hover:text-gray-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            aria-label="{{ __('common.toggle_dark_mode') }}"
        >
            <template x-if="dark">
                <x-ui.icon name="sun" class="size-5" aria-hidden="true" />
            </template>
            <template x-if="!dark">
                <x-ui.icon name="moon" class="size-5" aria-hidden="true" />
            </template>
        </button>

        {{-- User dropdown --}}
        <x-ui.dropdown
            align="end"
            width="w-56" label="{{ __('auth.account_menu') }}"
            class="rounded-full p-1 hover:bg-gray-100 dark:hover:bg-gray-700"
        >
            <x-slot:trigger>
                <span class="flex items-center gap-2 rounded-lg p-1.5">
                    <span class="grid size-8 place-items-center rounded-full bg-brand-600 text-xs font-semibold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()?->name ?? __('auth.guest'), 0, 1)) }}</span>
                    <span class="hidden md:block text-start leading-tight">
                        <span class="block text-sm font-medium text-gray-700 dark:text-gray-200">{{ auth()->user()?->name ?? __('auth.guest') }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ auth()->user()?->email ?? __('auth.preview') }}</span>
                    </span>
                </span>
            </x-slot:trigger>
            <x-slot:items>
                <div class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-200">
                    <span class="grid size-8 place-items-center rounded-full bg-brand-600 text-xs font-semibold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()?->name ?? __('auth.guest'), 0, 1)) }}</span>
                    <div class="min-w-0 leading-tight">
                        <span class="block truncate text-sm font-medium text-gray-700 dark:text-gray-200">{{ auth()->user()?->name ?? __('auth.guest') }}</span>
                        <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ auth()->user()?->email ?? __('auth.preview') }}</span>
                    </div>
                </div>
                <x-ui.dropdown-item href="#" icon="user">{{ __('common.profile') }}</x-ui.dropdown-item>
                @can('settings.view')
                    <x-ui.dropdown-item :href="route('settings.index')" icon="cog">{{ __('common.settings') }}</x-ui.dropdown-item>
                @endcan
                <div class="my-1 border-t border-gray-200 dark:border-gray-700"></div>
                @auth
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-ui.button type="submit" variant="ghost" icon="arrow-left-on-rectangle" role="menuitem" class="w-full justify-start">{{ __('auth.logout') }}</x-ui.button>
                    </form>
                @else
                    <x-ui.dropdown-item :href="route('login')">{{ __('auth.sign_in') }}</x-ui.dropdown-item>
                @endauth
            </x-slot:items>
        </x-ui.dropdown>
    </div>
</header>