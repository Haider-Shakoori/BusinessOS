@props(['item' => []])

@php
    $active = App\Support\Navigation::isActive($item);
    $href = $item['href'] ?? '#';
    $disabled = (bool) ($item['disabled'] ?? false);
    $depth = (int) ($item['depth'] ?? 0);
    $expandable = (bool) ($item['expandable'] ?? false);
    $badge = $item['badge'] ?? null;
@endphp

<a
    href="{{ $href }}"
    @if ($active) aria-current="page" @endif
    @if ($disabled) aria-disabled="true" x-on:click.prevent @endif
    title="{{ $item['label'] }}"
    class="group flex min-h-[31px] items-center gap-3 rounded-[6px] py-1.5 text-[12px] font-medium transition-all duration-150 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand-300 {{ $depth > 0 ? 'ps-5 pe-2' : 'px-2.5' }} {{ $active
        ? 'bg-white/[0.08] text-white'
        : 'text-slate-200 hover:bg-white/[0.06] hover:text-white' }}"
    x-bind:class="collapsed ? 'justify-center px-2' : 'justify-start'"
>
    <x-ui.icon
        :name="$item['icon']"
        :class="'size-[16px] shrink-0 '.($active ? 'text-white' : 'text-slate-300/90 group-hover:text-white')"
    />

    <span class="min-w-0 flex-1 truncate" x-bind:class="collapsed ? 'sr-only' : ''">{{ $item['label'] }}</span>

    @if ($badge !== null)
        <span
            class="inline-flex min-w-5 items-center justify-center rounded-full bg-rose-500 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white"
            x-bind:class="collapsed ? 'absolute end-0 top-0' : ''"
        >
            {{ $badge }}
        </span>
    @endif

    @if ($expandable)
        <x-ui.icon
            name="chevron-right"
            class="size-3.5 shrink-0 text-slate-400 rtl-flip"
            x-bind:class="collapsed ? 'hidden' : ''"
        />
    @endif
</a>
