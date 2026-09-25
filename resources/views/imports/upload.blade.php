@extends('layouts.app')

@section('content')
    <x-app.page
        :title="$title"
        :subtitle="$subtitle"
        :breadcrumbs="[
            ['label' => __('modules.dashboard'), 'url' => route('app.home')],
            ['label' => __('modules.' . $type), 'url' => route($routes['index'])],
            ['label' => $title],
        ]"
    >
        @if (session('status'))
            <div class="mb-6">
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            </div>
        @endif

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('imports.upload_title') }}</h2>
                    </x-slot:header>

                    <form
                        method="POST"
                        action="{{ route($routes['preview']) }}"
                        enctype="multipart/form-data"
                        class="space-y-5"
                    >
                        @csrf

                        <x-ui.file-input
                            name="file"
                            :label="__('imports.csv_file')"
                            :helper="__('imports.csv_helper', ['max_kb' => $maxFileKb, 'max_rows' => $maxRows])"
                            accept=".csv,text/csv"
                            required
                        />

                        <div class="flex flex-wrap items-center gap-3">
                            <x-ui.button type="submit" icon="document-text">{{ __('imports.upload') }}</x-ui.button>
                            <x-ui.button :href="route($routes['template'])" variant="secondary" icon="inbox">
                                {{ __('imports.download_template') }}
                            </x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            </div>

            <div class="lg:col-span-1">
                <x-ui.card>
                    <x-slot:header>
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('imports.instructions_title') }}</h2>
                    </x-slot:header>

                    <ul class="space-y-4 text-sm text-slate-600 dark:text-slate-300">
                        <li class="flex items-start gap-3">
                            <x-ui.icon name="document-text" class="mt-0.5 size-4 shrink-0 text-brand-500 dark:text-brand-400" />
                            <span>{{ __('imports.instruction_template') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <x-ui.icon name="check-circle" class="mt-0.5 size-4 shrink-0 text-brand-500 dark:text-brand-400" />
                            <span>{{ __('imports.instruction_encoding') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <x-ui.icon name="eye" class="mt-0.5 size-4 shrink-0 text-brand-500 dark:text-brand-400" />
                            <span>{{ __('imports.instruction_preview') }}</span>
                        </li>
                        <li class="flex items-start gap-3">
                            <x-ui.icon name="shield-check" class="mt-0.5 size-4 shrink-0 text-brand-500 dark:text-brand-400" />
                            <span>{{ __('imports.instruction_all_or_nothing') }}</span>
                        </li>
                    </ul>
                </x-ui.card>
            </div>
        </div>
    </x-app.page>
@endsection