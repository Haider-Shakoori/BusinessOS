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
            class="rounded-[7px] p-1 hover:bg-slate-100 dark:hover:bg-slate-700"
        >
            <x-slot:trigger>
                <span class="flex min-w-0 items-center gap-2">
                    <span class="grid size-8 shrink-0 place-items-center rounded-[7px] bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300" aria-hidden="true">
                        <x-ui.icon name="briefcase" class="size-4" />
                    </span>
                    <span class="hidden min-w-0 max-w-[10rem] text-start md:block">
                        <span class="block text-xs text-slate-500 dark:text-slate-400">{{ __('business.switch') }}</span>
                        <span class="block truncate text-sm font-medium text-slate-700 dark:text-slate-200" title="{{ $current->name }}">{{ $current->name }}</span>
                    </span>
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