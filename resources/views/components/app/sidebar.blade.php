@props(['items' => []])

<aside
    id="app-sidebar"
    class="fixed inset-y-0 start-0 z-30 hidden lg:flex w-64 flex-col border-e border-gray-200 bg-white sidebar-collapsed:lg:w-16 transition-[width] duration-200 ease-out dark:border-gray-700 dark:bg-gray-900"
    aria-label="{{ __('common.skip_to_content') }}"
>
    <x-app.brand :appName="__('common.app_name')" />

    <nav id="app-navigation" class="flex-1 overflow-y-auto overflow-x-hidden px-3 py-5">
        <x-app.navigation :items="$items" />
    </nav>

    <div class="shrink-0 border-t border-gray-200 p-3 dark:border-gray-700">
        <div class="flex items-center gap-3" x-bind:class="collapsed ? 'justify-center' : 'justify-between'">
            <x-app.account />
            <button
                type="button"
                x-on:click="toggleSidebar"
                x-bind:aria-label="collapsed ? '{{ __('common.expand_sidebar') }}' : '{{ __('common.collapse_sidebar') }}'"
                class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:text-gray-500 dark:hover:bg-gray-800 dark:hover:text-gray-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600"
            >
                <x-ui.icon name="chevron-left" class="rtl-flip size-4 transition-transform duration-200" x-bind:class="collapsed ? 'rotate-180' : ''" aria-hidden="true" />
            </button>
        </div>
    </div>
</aside>