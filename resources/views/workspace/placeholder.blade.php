@extends('layouts.app')

@section('content')
    <x-app.page
        :title="$title"
        :subtitle="__('workspace.placeholder_subtitle')"
        :icon="$icon"
        :breadcrumbs="[
            ['label' => __('navigation.dashboard'), 'url' => route('app.home')],
            ['label' => $title],
        ]"
    >
        <x-ui.card class="max-w-3xl">
            <div class="flex flex-col items-center px-4 py-10 text-center sm:py-14">
                <span class="grid size-14 place-items-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
                    <x-ui.icon :name="$icon" class="size-7" />
                </span>
                <h2 class="mt-5 text-lg font-semibold text-slate-900 dark:text-white">{{ $title }}</h2>
                <p class="mt-2 max-w-xl text-sm leading-6 text-slate-500 dark:text-slate-400">
                    {{ __('workspace.placeholder_message') }}
                </p>
                <x-ui.button class="mt-6" href="{{ route('app.home') }}" icon="arrow-uturn-left">
                    {{ __('workspace.back_to_dashboard') }}
                </x-ui.button>
            </div>
        </x-ui.card>
    </x-app.page>
@endsection
