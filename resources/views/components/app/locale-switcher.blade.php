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
    class="rounded-lg px-2 py-1.5 text-[12px] hover:bg-slate-100 dark:hover:bg-slate-800"
>
    <x-slot:trigger>
        <span class="flex items-center gap-1.5 font-medium text-slate-700 dark:text-slate-200">
            <span>{{ $currentLocale['native'] ?? strtoupper($current) }}</span>
        </span>
    </x-slot:trigger>
    <x-slot:items>
        @foreach ($supported as $code => $locale)
            <x-ui.dropdown-item
                href="{{ route('locale.switch', $code) }}"
                :disabled="$code === $current"
            >
                <span class="flex w-full items-center justify-between gap-2">
                    {{ $locale['native'] }}
                    @if ($code === $current)
                        <x-ui.icon name="check" class="size-4 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                    @endif
                </span>
            </x-ui.dropdown-item>
        @endforeach
    </x-slot:items>
</x-ui.dropdown>
