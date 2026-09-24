<header class="app-header sticky top-0 z-20 flex h-14 items-center border-b border-slate-200 bg-white px-3 shadow-[0_1px_0_rgba(15,23,42,0.02)] sm:px-5 dark:border-slate-800 dark:bg-slate-900">
    <button
        type="button"
        x-on:click="$dispatch('bos:open-drawer', { id: 'app-mobile-nav' })"
        class="lg:hidden inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
        aria-controls="app-mobile-nav"
        aria-label="{{ __('common.open_navigation') }}"
        aria-haspopup="dialog"
    >
        <x-ui.icon name="menu" class="size-[18px]" aria-hidden="true" />
    </button>

    <button
        type="button"
        x-on:click="toggleSidebar"
        x-bind:aria-label="collapsed ? '{{ __('common.expand_sidebar') }}' : '{{ __('common.collapse_sidebar') }}'"
        class="hidden lg:inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
    >
        <x-ui.icon name="menu" class="size-[18px]" aria-hidden="true" />
    </button>

    <div class="ms-auto hidden w-full max-w-[310px] md:block lg:ms-[auto]">
        <button
            type="button"
            class="group flex h-9 w-full items-center gap-2 rounded-[7px] border border-slate-300 bg-white px-3 text-[13px] text-slate-400 shadow-sm transition-colors hover:border-slate-400 focus-visible:outline-2 focus-visible:outline-brand-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-500"
            aria-label="{{ __('common.search_coming_soon') }}"
            title="{{ __('common.search_coming_soon') }}"
        >
            <x-ui.icon name="search" class="size-4 text-slate-500" aria-hidden="true" />
            <span class="truncate">{{ __('common.search') }}</span>
        </button>
    </div>

    <div class="ms-auto flex items-center gap-1 md:ms-4">
        <div class="hidden xl:block">
            <x-app.business-switcher />
        </div>

        <span class="mx-1 hidden h-6 w-px bg-slate-200 sm:block dark:bg-slate-700" aria-hidden="true"></span>

        <button
            type="button"
            x-data="themeSwitcher"
            x-on:click="toggle"
            class="inline-flex size-9 items-center justify-center rounded-lg text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
            aria-label="{{ __('common.toggle_dark_mode') }}"
        >
            <template x-if="dark"><x-ui.icon name="sun" class="size-[18px]" aria-hidden="true" /></template>
            <template x-if="!dark"><x-ui.icon name="moon" class="size-[18px]" aria-hidden="true" /></template>
        </button>

        <span class="mx-1 hidden h-6 w-px bg-slate-200 sm:block dark:bg-slate-700" aria-hidden="true"></span>

        <x-app.locale-switcher />

        <span class="mx-1 hidden h-6 w-px bg-slate-200 sm:block dark:bg-slate-700" aria-hidden="true"></span>

        <x-ui.dropdown
            align="end"
            width="w-60"
            label="{{ __('auth.account_menu') }}"
            class="rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800"
        >
            <x-slot:trigger>
                <span class="flex items-center gap-2.5 px-1.5 py-1">
                    <span class="grid size-9 place-items-center rounded-full bg-brand-600 text-xs font-semibold text-white shadow-sm" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()?->name ?? __('auth.guest'), 0, 1)) }}</span>
                    <span class="hidden max-w-[160px] text-start leading-[1.15] md:block">
                        <span class="block truncate text-[13px] font-medium text-slate-800 dark:text-slate-100">{{ auth()->user()?->name ?? __('auth.guest') }}</span>
                        <span class="mt-0.5 block truncate text-[11px] text-slate-500 dark:text-slate-400">{{ auth()->user()?->email ?? __('auth.preview') }}</span>
                    </span>
                    <x-ui.icon name="chevron-down" class="hidden size-3.5 text-slate-400 md:block" aria-hidden="true" />
                </span>
            </x-slot:trigger>
            <x-slot:items>
                <div class="px-3 py-2.5">
                    <p class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ auth()->user()?->name ?? __('auth.guest') }}</p>
                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ auth()->user()?->email ?? __('auth.preview') }}</p>
                </div>
                @can('settings.view')
                    <x-ui.dropdown-item :href="route('settings.index')" icon="cog">{{ __('common.settings') }}</x-ui.dropdown-item>
                @endcan
                <div class="my-1 border-t border-slate-200 dark:border-slate-700"></div>
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
