@props(['items' => []])

@php
    $productSubtitle = config('product.plan_label')
        ?: trim(config('product.version').' • '.config('product.batch_label'));
@endphp

<aside
    id="app-sidebar"
    class="bos-sidebar fixed inset-y-0 start-0 z-30 hidden w-[200px] flex-col border-e border-white/5 shadow-[4px_0_24px_rgba(15,23,42,0.08)] transition-[width] duration-200 ease-out lg:flex sidebar-collapsed:lg:w-[64px]"
    aria-label="{{ __('common.skip_to_content') }}"
>
    <x-app.brand :appName="__('common.app_name')" />

    <nav id="app-navigation" class="bos-scrollbar flex-1 overflow-y-auto overflow-x-hidden px-2 py-2.5">
        <x-app.navigation :items="$items" />
    </nav>

    <div class="shrink-0 border-t border-white/10 p-2.5" x-bind:class="collapsed ? 'px-2' : 'px-2.5'">
        <div class="flex items-center gap-2.5 rounded-[8px] border border-white/10 bg-white/[0.04] px-2.5 py-2" x-bind:class="collapsed ? 'justify-center px-2' : ''">
            <span class="relative grid size-8 shrink-0 place-items-center rounded-[7px] bg-brand-500/15 text-brand-300">
                <span class="relative block size-4" aria-hidden="true">
                    <span class="absolute start-0 top-0 h-[11px] w-[9px] rounded-[2px] bg-brand-400"></span>
                    <span class="absolute bottom-0 end-0 h-[11px] w-[9px] rounded-[2px] bg-brand-300/90"></span>
                </span>
            </span>
            <span class="min-w-0 flex-1" x-bind:class="collapsed ? 'sr-only' : ''">
                <span class="block truncate text-[11px] font-semibold text-slate-100">BusinessOS</span>
                <span class="mt-0.5 block truncate text-[9px] text-slate-400">{{ $productSubtitle }}</span>
            </span>
            <span class="size-2 shrink-0 rounded-full bg-emerald-400" x-bind:class="collapsed ? 'hidden' : ''" aria-hidden="true"></span>
        </div>
    </div>
</aside>
