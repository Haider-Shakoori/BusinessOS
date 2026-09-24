@props(['items' => []])

<aside
    id="app-sidebar"
    class="bos-sidebar fixed inset-y-0 start-0 z-30 hidden w-[208px] flex-col border-e border-white/5 shadow-[4px_0_24px_rgba(15,23,42,0.06)] transition-[width] duration-200 ease-out lg:flex sidebar-collapsed:lg:w-[68px]"
    aria-label="{{ __('common.skip_to_content') }}"
>
    <x-app.brand :appName="__('common.app_name')" />

    <nav id="app-navigation" class="bos-scrollbar flex-1 overflow-y-auto overflow-x-hidden px-2 py-4">
        <x-app.navigation :items="$items" />
    </nav>
</aside>
