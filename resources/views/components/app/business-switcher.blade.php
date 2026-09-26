@props([
    'showLabelOnMobile' => false,
])

@php
    $context = app(\App\Services\BusinessContext::class);
    $current = $context->current();
    $businesses = $context->businesses();
    $settings = $current ? app(\App\Services\BusinessSettings::class) : null;
    $address = $settings?->get('general.address');
@endphp

@auth
    @if ($current)
        <x-ui.dropdown
            align="end"
            width="w-64"
            aria-label="{{ __('business.switch_heading') }}"
            class="rounded-[7px] border border-slate-200 bg-white px-2 py-1 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:hover:bg-slate-800"
        >
            <x-slot:trigger>
                <span class="flex min-w-0 items-center gap-2">
                    <span class="grid size-7 shrink-0 place-items-center rounded-[6px] bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300" aria-hidden="true">
                        <x-ui.icon name="building-office" class="size-4" />
                    </span>
                    <span class="{{ $showLabelOnMobile ? 'block' : 'hidden lg:block' }} min-w-0 max-w-[9.5rem] text-start leading-[1.1]">
                        <span class="block truncate text-[11px] font-semibold text-slate-800 dark:text-slate-100" title="{{ $current->name }}">{{ $current->name }}</span>
                        <span class="mt-0.5 block truncate text-[9px] text-slate-500 dark:text-slate-400">{{ $address ?: __('business.current_business') }}</span>
                    </span>
                    <x-ui.icon name="chevron-down" class="{{ $showLabelOnMobile ? 'block' : 'hidden lg:block' }} size-3 text-slate-400" />
                </span>
            </x-slot:trigger>

            <x-slot:items>
                <div class="px-3 pb-1 pt-2 text-xs font-medium uppercase tracking-wide text-slate-400 dark:text-slate-500">{{ __('business.businesses') }}</div>
                @foreach ($businesses as $business)
                    <form method="POST" action="{{ route('business.switch') }}" class="p-0">
                        @csrf
                        <input type="hidden" name="business_id" value="{{ $business->id }}">
                        <button
                            type="submit"
                            role="menuitem"
                            class="flex w-full items-center gap-2.5 rounded-[7px] px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-white"
                            title="{{ $business->name }}"
                        >
                            <span class="grid size-6 shrink-0 place-items-center rounded-md bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300" aria-hidden="true">
                                <x-ui.icon name="building-office" class="size-3.5" />
                            </span>
                            <span class="min-w-0 flex-1 truncate text-start">{{ $business->name }}</span>
                            @if ($business->id === $current->id)
                                <x-ui.icon name="check" class="size-4 shrink-0 text-brand-600 dark:text-brand-400" aria-hidden="true" />
                            @endif
                        </button>
                    </form>
                @endforeach
            </x-slot:items>
        </x-ui.dropdown>
    @else
        <div class="hidden items-center gap-2 rounded-[7px] border border-slate-200 bg-white px-2.5 py-1.5 lg:flex dark:border-slate-700 dark:bg-slate-900">
            <span class="grid size-6 place-items-center rounded-[6px] bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
                <x-ui.icon name="building-office" class="size-3.5" />
            </span>
            <span class="text-[10px] font-semibold text-slate-600 dark:text-slate-300">{{ __('business.setup_in_progress') }}</span>
        </div>
    @endif
@endauth
