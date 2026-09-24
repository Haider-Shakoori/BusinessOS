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
    class="rounded-lg p-1 hover:bg-gray-100 dark:hover:bg-gray-700"
>
    <x-slot:trigger>
        <span class="flex items-center gap-1.5">
            <x-ui.icon name="globe" class="size-5" aria-hidden="true" />
            @if ($currentLocale)
                <span class="hidden sm:inline text-xs font-medium">{{ $currentLocale['native'] }}</span>
            @endif
        </span>
    </x-slot:trigger>
    <x-slot:items>
        @foreach ($supported as $code => $locale)
            <x-ui.dropdown-item
                href="{{ route('locale.switch', $code) }}"
                :disabled="$code === $current"
            >
                <span class="flex items-center gap-2">
                    {{ $locale['native'] }}
                    @if ($code === $current)
                        <x-ui.icon name="check" class="size-4 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                    @endif
                </span>
            </x-ui.dropdown-item>
        @endforeach
    </x-slot:items>
</x-ui.dropdown>
