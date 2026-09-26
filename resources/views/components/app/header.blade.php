<header class="app-header sticky top-0 z-20 flex h-12 items-center border-b border-slate-200 bg-white px-3 shadow-[0_1px_0_rgba(15,23,42,0.02)] sm:px-5 dark:border-slate-800 dark:bg-slate-900">
    <button
        type="button"
        x-on:click="$dispatch('bos:open-drawer', { id: 'app-mobile-nav' })"
        class="lg:hidden inline-flex size-8 shrink-0 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
        aria-controls="app-mobile-nav"
        aria-label="{{ __('common.open_navigation') }}"
        aria-haspopup="dialog"
    >
        <x-ui.icon name="menu" class="size-[17px]" aria-hidden="true" />
    </button>

    <button
        type="button"
        x-on:click="toggleSidebar"
        x-bind:aria-label="collapsed ? '{{ __('common.expand_sidebar') }}' : '{{ __('common.collapse_sidebar') }}'"
        class="hidden lg:inline-flex size-8 shrink-0 items-center justify-center rounded-lg text-slate-500 transition-colors hover:bg-slate-100 hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white"
    >
        <x-ui.icon name="menu" class="size-[17px]" aria-hidden="true" />
    </button>

    <div class="ms-3 hidden w-full max-w-[480px] md:block lg:ms-4">
        <a
            href="{{ auth()->check() && app(\App\Services\BusinessContext::class)->current() && \Illuminate\Support\Facades\Gate::allows('search.use') ? route('system.search.index') : '#' }}"
            class="group flex h-8.5 w-full items-center gap-2 rounded-[6px] border border-slate-200 bg-slate-50/70 px-3 text-[11px] text-slate-400 shadow-sm transition-colors hover:border-slate-300 hover:bg-white focus-visible:outline-2 focus-visible:outline-brand-500 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-500"
            aria-label="{{ __('navigation.global_search') }}"
        >
            <x-ui.icon name="search" class="size-3.5 text-slate-500" aria-hidden="true" />
            <span class="truncate">{{ __('common.search') }}</span>
            <span class="ms-auto inline-flex items-center gap-1 rounded border border-slate-200 bg-white px-1.5 py-0.5 text-[9px] font-medium text-slate-400 dark:border-slate-700 dark:bg-slate-900">
                <span>⌘</span><span>{{ __('common.search_shortcut') }}</span>
            </span>
        </a>
    </div>

    <div class="ms-auto flex items-center gap-1">
        <div class="hidden xl:block">
            <x-app.business-switcher />
        </div>

        <span class="mx-1 hidden h-5 w-px bg-slate-200 xl:block dark:bg-slate-700" aria-hidden="true"></span>

        <div class="hidden sm:block">
            <x-app.locale-switcher />
        </div>

        <span class="mx-1 hidden h-5 w-px bg-slate-200 sm:block dark:bg-slate-700" aria-hidden="true"></span>

        <button
            type="button"
            x-data="themeSwitcher"
            x-on:click="toggle"
            class="inline-flex size-8 items-center justify-center rounded-lg text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
            aria-label="{{ __('common.toggle_dark_mode') }}"
        >
            <template x-if="dark"><x-ui.icon name="sun" class="size-[16px]" aria-hidden="true" /></template>
            <template x-if="!dark"><x-ui.icon name="moon" class="size-[16px]" aria-hidden="true" /></template>
        </button>

        <span class="mx-1 hidden h-5 w-px bg-slate-200 sm:block dark:bg-slate-700" aria-hidden="true"></span>

        <a
            href="{{ auth()->check() && app(\App\Services\BusinessContext::class)->current() && \Illuminate\Support\Facades\Gate::allows('notifications.view') ? route('system.notifications.index') : '#' }}"
            class="relative inline-flex size-8 items-center justify-center rounded-lg text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white"
            aria-label="{{ __('common.notification_center') }}"
        >
            <x-ui.icon name="bell" class="size-[17px]" aria-hidden="true" />
        </a>

        <span class="mx-1 hidden h-5 w-px bg-slate-200 sm:block dark:bg-slate-700" aria-hidden="true"></span>

        <x-ui.dropdown
            align="end"
            width="w-60"
            label="{{ __('auth.account_menu') }}"
            class="rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800"
        >
            <x-slot:trigger>
                <span class="flex items-center gap-2 px-1 py-0.5">
                    <span class="grid size-8 place-items-center rounded-full bg-brand-600 text-[11px] font-semibold text-white shadow-sm" aria-hidden="true">{{ mb_strtoupper(mb_substr(auth()->user()?->name ?? __('auth.guest'), 0, 1)) }}</span>
                    <span class="hidden max-w-[150px] text-start leading-[1.1] xl:block">
                        <span class="block truncate text-[11px] font-semibold text-slate-800 dark:text-slate-100">{{ auth()->user()?->name ?? __('auth.guest') }}</span>
                        <span class="mt-0.5 block truncate text-[9px] text-slate-500 dark:text-slate-400">{{ auth()->user()?->email ?? __('auth.preview') }}</span>
                    </span>
                    <x-ui.icon name="chevron-down" class="hidden size-3 text-slate-400 xl:block" aria-hidden="true" />
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
