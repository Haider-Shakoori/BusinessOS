@props([
    'items' => [],
    'default' => null,
    'label' => null,
])

@php
    $tabs = collect($items)->map(function ($item, $key) {
        if (is_array($item)) {
            return isset($item['key'])
                ? ['key' => $item['key'], 'label' => $item['label']]
                : ['key' => array_values($item)[0], 'label' => (string) (array_values($item)[1] ?? array_values($item)[0])];
        }
        return ['key' => $key, 'label' => (string) $item];
    })->values();

    $firstKey = $tabs->first()['key'] ?? null;
    $activeKey = $default ?? $firstKey;
@endphp

<div
    x-data="tabSet(@js($default ?? $firstKey))"
    class="w-full"
>
    <div
        role="tablist"
        @if ($label) aria-label="{{ $label }}" @endif
        class="flex gap-1 overflow-x-auto border-b border-gray-200 dark:border-gray-700"
    >
        @foreach ($tabs as $tab)
            <button
                type="button"
                role="tab"
                id="tab-{{ $tab['key'] }}"
                :aria-selected="active === '{{ $tab['key'] }}'"
                aria-selected="{{ $activeKey === $tab['key'] ? 'true' : 'false' }}"
                :tabindex="active === '{{ $tab['key'] }}' ? 0 : -1"
                x-on:click="select('{{ $tab['key'] }}')"
                :class="active === '{{ $tab['key'] }}' ? 'border-brand-600 text-brand-700 dark:border-brand-400 dark:text-brand-300' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:border-gray-600 dark:hover:text-gray-200'"
                class="-mb-px inline-flex shrink-0 items-center gap-1.5 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-brand-600"
            >
                {{ $tab['label'] }}
            </button>
        @endforeach
    </div>

    <div class="pt-4">
        {{ $slot }}
    </div>
</div>