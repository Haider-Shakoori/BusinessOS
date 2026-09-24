@props(['item' => []])

@php
    $active = App\Support\Navigation::isActive($item);
    $href = $item['href'] ?? '#';
@endphp

<a
    href="{{ $href }}"
    @if ($active) aria-current="page" @endif
    title="{{ $item['label'] }}"
    class="group relative flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand-600 {{ $active
        ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300'
        : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white' }}"
    x-bind:class="collapsed ? 'justify-center' : 'justify-start'"
>
    @if ($active)
        <span class="absolute inset-y-1 start-0 w-0.5 rounded-e-full bg-brand-600 dark:bg-brand-400" aria-hidden="true"></span>
    @endif

    <x-ui.icon
        :name="$item['icon']"
        :class="'size-5 shrink-0 '.($active ? 'text-brand-600 dark:text-brand-400' : 'text-gray-400 group-hover:text-gray-600 dark:text-gray-500 dark:group-hover:text-gray-300')"
    />

    <span class="truncate" x-bind:class="collapsed ? 'sr-only' : ''">{{ $item['label'] }}</span>
</a>