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
    <div class="absolute inset-0 bg-slate-950/55 backdrop-blur-[2px]" x-on:click="close" aria-hidden="true"></div>

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
        class="bos-sidebar absolute inset-y-0 start-0 flex w-72 max-w-[86vw] flex-col shadow-overlay"
    >
        <div class="flex items-center justify-between gap-2 pe-2" x-data="{ collapsed: false }">
            <x-app.brand :appName="__('common.app_name')" />
            <button
                type="button"
                x-on:click="close"
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-slate-400 transition-colors hover:bg-white/10 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-300"
                aria-label="{{ __('common.close_navigation') }}"
            >
                <x-ui.icon name="x-mark" class="size-5" aria-hidden="true" />
            </button>
        </div>

        <nav class="bos-scrollbar flex-1 overflow-y-auto overflow-x-hidden px-2 py-4" x-data="{ collapsed: false }">
            <x-app.navigation :items="$items" />
        </nav>

        <div class="shrink-0 border-t border-white/10 p-4" x-data="{ collapsed: false }">
            <div class="[&_*]:!text-slate-200">
                <x-app.account />
            </div>
        </div>
    </div>
</div>
