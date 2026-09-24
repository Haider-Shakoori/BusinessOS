@props([
    'paginator' => null,
    'onEachSide' => 1,
])

@php
    if (! $paginator) {
        return;
    }
    $first = $paginator->firstItem();
    $last = $paginator->lastItem();
    $total = method_exists($paginator, 'total') ? $paginator->total() : null;

    $current = $paginator->currentPage();
    $windowStart = max(1, $current - $onEachSide);
    $windowEnd = $paginator->lastPage() !== null ? min($paginator->lastPage(), $current + $onEachSide) : $current;
    $urlRange = $paginator->getUrlRange($windowStart, $windowEnd);

    $pageLinkClasses = 'inline-flex min-w-9 items-center justify-center rounded-lg px-2.5 py-2 text-sm font-medium
        transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600';
    $inactiveLink = 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700';
    $activeLink = 'bg-brand-600 text-white shadow-sm dark:bg-brand-500';
    $disabled = 'pointer-events-none opacity-40';
@endphp

@if ($paginator->hasPages())
    <nav
        role="navigation"
        aria-label="Pagination"
        class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
    >
        @if ($first !== null && $total !== null)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Showing
                <span class="font-medium text-gray-900 dark:text-white">{{ $first }}</span>
                to
                <span class="font-medium text-gray-900 dark:text-white">{{ $last }}</span>
                of
                <span class="font-medium text-gray-900 dark:text-white">{{ $total }}</span>
                results
            </p>
        @endif

        <div class="flex flex-wrap items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="{{ $pageLinkClasses }} {{ $disabled }}" aria-hidden="true">
                    <x-ui.icon name="chevron-left" class="rtl-flip size-4" />
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" class="{{ $pageLinkClasses }} {{ $inactiveLink }}" rel="prev" aria-label="Previous">
                    <x-ui.icon name="chevron-left" class="rtl-flip size-4" />
                </a>
            @endif

            @foreach ($urlRange as $page => $url)
                @if ($page == $current)
                    <span class="{{ $pageLinkClasses }} {{ $activeLink }}" aria-current="page">{{ $page }}</span>
                @else
                    <a href="{{ $url }}" class="{{ $pageLinkClasses }} {{ $inactiveLink }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" class="{{ $pageLinkClasses }} {{ $inactiveLink }}" rel="next" aria-label="Next">
                    <x-ui.icon name="chevron-right" class="rtl-flip size-4" />
                </a>
            @else
                <span class="{{ $pageLinkClasses }} {{ $disabled }}" aria-hidden="true">
                    <x-ui.icon name="chevron-right" class="rtl-flip size-4" />
                </span>
            @endif
        </div>
    </nav>
@endif