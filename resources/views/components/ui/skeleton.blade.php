@props([
    'lines' => 3,
    'widths' => ['w-full', 'w-2/3', 'w-1/3'],
    'block' => false,
])

@if ($block)
    <div class="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-5 dark:border-gray-700 dark:bg-gray-800">
        <div class="size-11 shrink-0 animate-pulse rounded-lg bg-gray-200 dark:bg-gray-700"></div>
        <div class="w-full flex-1 space-y-2 animate-pulse">
            <div class="h-3 w-1/4 rounded bg-gray-200 dark:bg-gray-700"></div>
            <div class="h-4 w-2/3 rounded bg-gray-200 dark:bg-gray-700"></div>
        </div>
    </div>
@else
    <div class="space-y-2 animate-pulse">
        @for ($i = 0; $i < $lines; $i++)
            <div class="h-3.5 rounded bg-gray-200 dark:bg-gray-700 {{ $widths[$i % count($widths)] }}"></div>
        @endfor
    </div>
@endif