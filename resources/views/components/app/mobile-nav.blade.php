<div
    x-data="drawerPanel(@js(false), @js('app-mobile-nav'))"
    x-cloak
    x-show="open"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed inset-0 z-50 lg:hidden"
    role="dialog"
    aria-modal="true"
    aria-label="{{ __('common.mobile_navigation') }}"
    @keydown.window.escape="close"
>
    <div class="absolute inset-0 bg-gray-950/50 backdrop-blur-[2px]" x-on:click="close" aria-hidden="true"></div>

    <div
        x-ref="panel"
        tabindex="-1"
        x-show="open"
        x-transition:enter="transition-[inset-inline-start] ease-out duration-300"
        x-transition:enter-start="[inset-inline-start:-100%]"
        x-transition:enter-end="start-0"
        x-transition:leave="transition-[inset-inline-start] ease-in duration-200"
        x-transition:leave-start="start-0"
        x-transition:leave-end="[inset-inline-start:-100%]"
        class="absolute inset-y-0 start-0 flex w-72 max-w-[85vw] flex-col bg-white shadow-overlay dark:bg-gray-900"
    >
        <div class="flex items-center justify-between gap-2 pe-2">
            <div x-data="{ collapsed: false }">
                <x-app.brand :appName="__('common.app_name')" />
            </div>
            <button
                type="button"
                x-on:click="close"
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
                aria-label="{{ __('common.close_navigation') }}"
            >
                <x-ui.icon name="x-mark" class="size-5" aria-hidden="true" />
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 py-5" x-data="{ collapsed: false }">
            <x-app.navigation :items="$items" />
        </nav>

        <div class="shrink-0 border-t border-gray-200 p-4 dark:border-gray-700">
            <x-app.account />
        </div>
    </div>
</div>