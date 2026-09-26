@props([
    'current' => null,
])

@php
    $current = $current ?? app()->getLocale();
    $supported = config('localization.supported', []);
    $currentLocale = $supported[$current] ?? null;
@endphp

<x-ui.dropdown
    align="end"
    width="w-48"
    label="{{ __('common.language') }}"
    class="rounded-lg px-1.5 py-1 text-[11px] hover:bg-slate-100 dark:hover:bg-slate-800"
>
    <x-slot:trigger>
        <span class="flex items-center gap-1.5 font-medium text-slate-700 dark:text-slate-200">
            <x-ui.icon name="globe" class="size-3.5 text-slate-600 dark:text-slate-300" />
            <span class="hidden sm:inline">{{ $currentLocale['native'] ?? strtoupper($current) }}</span>
            <x-ui.icon name="chevron-down" class="hidden size-3 text-slate-400 sm:block" />
        </span>
    </x-slot:trigger>
    <x-slot:items>
        @foreach ($supported as $code => $locale)
            <x-ui.dropdown-item
                href="{{ route('locale.switch', $code) }}"
                :disabled="$code === $current"
            >
                <span class="flex w-full items-center justify-between gap-2">
                    <span>
                        <span class="block text-sm">{{ $locale['native'] }}</span>
                        <span class="block text-[10px] text-slate-400">{{ $locale['label'] }}</span>
                    </span>
                    @if ($code === $current)
                        <x-ui.icon name="check" class="size-4 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                    @endif
                </span>
            </x-ui.dropdown-item>
        @endforeach
    </x-slot:items>
</x-ui.dropdown>
