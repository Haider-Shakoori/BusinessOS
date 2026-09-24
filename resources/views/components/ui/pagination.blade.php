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

    $pageLinkClasses = 'inline-flex size-9 items-center justify-center rounded-[6px] border text-[12px] font-medium transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-brand-600';
    $inactiveLink = 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800';
    $activeLink = 'border-brand-600 bg-brand-600 text-white shadow-sm';
    $disabled = 'pointer-events-none border-slate-200 bg-slate-50 text-slate-300 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-700';
@endphp

@if ($paginator->hasPages())
    <nav role="navigation" aria-label="Pagination" class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        @if ($first !== null && $total !== null)
            <p class="text-[12px] text-slate-600 dark:text-slate-400">
                Showing <span class="font-medium text-slate-900 dark:text-white">{{ $first }}</span>
                to <span class="font-medium text-slate-900 dark:text-white">{{ $last }}</span>
                of <span class="font-medium text-slate-900 dark:text-white">{{ $total }}</span> results
            </p>
        @endif

        <div class="flex flex-wrap items-center gap-1.5">
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
