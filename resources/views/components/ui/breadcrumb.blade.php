@props([
    'items' => [],
])

<nav aria-label="Breadcrumb">
    <ol class="flex flex-wrap items-center gap-1.5 text-sm">
        @foreach ($items as $item)
            <li class="flex items-center gap-1.5">
                @if (isset($item['url']) && ! $loop->last)
                    <a href="{{ $item['url'] }}" class="text-gray-500 transition-colors hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100">
                        {{ $item['label'] }}
                    </a>
                    <x-ui.icon name="chevron-right" class="rtl-flip size-3.5 text-gray-400 dark:text-gray-500" aria-hidden="true" />
                @else
                    <span class="font-medium text-gray-900 dark:text-white" aria-current="page">{{ $item['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>