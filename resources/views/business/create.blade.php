@extends('layouts.app')
@section('content')
    <x-app.page :title="__('business.create_title')" :subtitle="__('business.create_subtitle')">
        <x-ui.card class="max-w-xl">
            <div class="mb-6 flex items-start gap-4">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300" aria-hidden="true">
                    <x-ui.icon name="briefcase" class="size-5" />
                </span>
                <div class="min-w-0">
                    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('business.onboarding_heading') }}</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ __('business.onboarding_message') }}</p>
                </div>
            </div>

            <form method="POST" action="{{ route('business.store') }}" class="space-y-5">
                @csrf
                <x-ui.input
                    name="name"
                    :label="__('business.name_label')"
                    :placeholder="__('business.name_placeholder')"
                    :helper="__('business.name_helper')"
                    required
                    autofocus
                />
                <div class="flex items-center justify-end">
                    <x-ui.button type="submit" icon="plus">{{ __('business.submit_create') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </x-app.page>
@endsection