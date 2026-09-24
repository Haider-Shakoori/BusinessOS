@extends('layouts.app')
@section('content')
    <x-app.page :title="__('authorization.forbidden')">
        <x-ui.card class="max-w-xl">
            <div class="flex items-start gap-4">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400" aria-hidden="true">
                    <x-ui.icon name="exclamation-triangle" class="size-5" />
                </span>
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('authorization.forbidden') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('authorization.unauthorized') }}</p>
                </div>
            </div>
        </x-ui.card>
    </x-app.page>
@endsection