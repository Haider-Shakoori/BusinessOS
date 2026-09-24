@php
    $context = app(\App\Services\BusinessContext::class);
    $current = $context->current();
    $businesses = $context->businesses();
@endphp

@auth
    @if ($current)
        <x-ui.dropdown
            align="end"
            width="w-64"
            aria-label="{{ __('business.switch_heading') }}"
            class="rounded-lg p-1 hover:bg-gray-100 dark:hover:bg-gray-700"
        >
            <x-slot:trigger>
                <span class="flex min-w-0 items-center gap-2">
                    <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300" aria-hidden="true">
                        <x-ui.icon name="briefcase" class="size-4" />
                    </span>
                    <span class="hidden min-w-0 max-w-[10rem] text-start md:block">
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ __('business.switch') }}</span>
                        <span class="block truncate text-sm font-medium text-gray-700 dark:text-gray-200" title="{{ $current->name }}">{{ $current->name }}</span>
                    </span>
                </span>
            </x-slot:trigger>

            <x-slot:items>
                <div class="px-3 pb-1 pt-2 text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('business.businesses') }}</div>
                @foreach ($businesses as $business)
                    <form method="POST" action="{{ route('business.switch') }}" class="p-0">
                        @csrf
                        <input type="hidden" name="business_id" value="{{ $business->id }}">
                        <button
                            type="submit"
                            role="menuitem"
                            class="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white"
                            title="{{ $business->name }}"
                        >
                            <span class="grid size-6 shrink-0 place-items-center rounded-md bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-300" aria-hidden="true">
                                <x-ui.icon name="briefcase" class="size-3.5" />
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
    @endif
@endauth