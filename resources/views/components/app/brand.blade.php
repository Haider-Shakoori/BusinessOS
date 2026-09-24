@props(['appName' => null])

<div class="flex h-14 shrink-0 items-center border-b border-white/10 px-4" x-bind:class="collapsed ? 'justify-center px-2' : ''">
    <div class="flex min-w-0 items-center gap-2.5">
        <span class="relative block size-7 shrink-0" aria-hidden="true">
            <span class="absolute start-0 top-0 h-[18px] w-[15px] rounded-[3px] bg-brand-500"></span>
            <span class="absolute bottom-0 end-0 h-[18px] w-[15px] rounded-[3px] bg-brand-400/90"></span>
            <span class="absolute start-[7px] top-[7px] size-[11px] rounded-[2px] bg-brand-600 shadow-sm"></span>
        </span>
        <span class="truncate text-[17px] font-semibold tracking-[-0.02em] text-white" x-bind:class="collapsed ? 'sr-only' : ''">
            Business<span class="text-brand-400">OS</span>
        </span>
    </div>
</div>
