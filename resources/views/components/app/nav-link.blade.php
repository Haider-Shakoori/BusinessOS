@props(['item' => []])

@php
    $active = App\Support\Navigation::isActive($item);
    $href = $item['href'] ?? '#';
@endphp

<a
    href="{{ $href }}"
    @if ($active) aria-current="page" @endif
    title="{{ $item['label'] }}"
    class="group flex min-h-10 items-center gap-3 rounded-[7px] px-3 py-2 text-[13px] font-medium transition-all duration-150 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand-300 {{ $active
        ? 'bg-brand-600 text-white shadow-[0_4px_14px_rgba(20,115,230,0.28)]'
        : 'text-slate-300 hover:bg-white/[0.07] hover:text-white' }}"
    x-bind:class="collapsed ? 'justify-center px-2' : 'justify-start'"
>
    <x-ui.icon
        :name="$item['icon']"
        :class="'size-[18px] shrink-0 '.($active ? 'text-white' : 'text-slate-400 group-hover:text-white')"
    />
    <span class="truncate" x-bind:class="collapsed ? 'sr-only' : ''">{{ $item['label'] }}</span>
</a>
