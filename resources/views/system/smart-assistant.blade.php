@extends('layouts.app')

@section('content')
<x-app.page icon="light-bulb" :title="__('assistant.title')" :subtitle="__('assistant.subtitle')">
    @if (session('status')) <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div> @endif
    @if ($errors->any()) <div class="mb-5"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div> @endif

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_290px]">
        <x-ui.card>
            <div class="flex min-h-[520px] flex-col">
                <div class="flex-1 space-y-4 overflow-y-auto">
                    @forelse($messages as $message)
                        <div class="flex {{ $message->role === 'user' ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[85%] rounded-2xl px-4 py-3 text-sm leading-6
                                {{ $message->role === 'user'
                                    ? 'bg-brand-600 text-white'
                                    : 'border border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200' }}">
                                {{ $message->content }}
                            </div>
                        </div>
                    @empty
                        <div class="grid min-h-[360px] place-items-center text-center">
                            <div class="max-w-lg">
                                <div class="mx-auto grid size-12 place-items-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
                                    <x-ui.icon name="light-bulb" class="size-6" />
                                </div>
                                <h2 class="mt-4 text-lg font-semibold text-slate-900 dark:text-white">{{ __('assistant.empty_title') }}</h2>
                                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('assistant.empty_text') }}</p>
                            </div>
                        </div>
                    @endforelse
                </div>

                <form method="POST" action="{{ route('system.assistant.ask') }}" class="mt-5 border-t border-slate-100 pt-4 dark:border-slate-700">
                    @csrf
                    <div class="flex gap-2">
                        <div class="min-w-0 flex-1">
                            <x-ui.input
                                name="question"
                                :label="__('assistant.question')"
                                :placeholder="__('assistant.placeholder')"
                                autocomplete="off"
                                required
                                maxlength="1000"
                            />
                        </div>
                        <div class="flex items-end">
                            <x-ui.button type="submit" icon="paper-airplane">{{ __('assistant.ask') }}</x-ui.button>
                        </div>
                    </div>
                </form>
            </div>
        </x-ui.card>

        <div class="space-y-5">
            <x-ui.card>
                <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('assistant.try_asking') }}</h2></x-slot:header>
                <div class="space-y-2">
                    @foreach(__('assistant.suggestions') as $suggestion)
                        <form method="POST" action="{{ route('system.assistant.ask') }}">
                            @csrf
                            <input type="hidden" name="question" value="{{ $suggestion }}">
                            <button type="submit" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-start text-xs text-slate-600 transition hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700 dark:border-slate-700 dark:text-slate-300 dark:hover:border-brand-700 dark:hover:bg-brand-500/10">
                                {{ $suggestion }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card>
                <h2 class="font-semibold text-slate-900 dark:text-white">{{ __('assistant.privacy_title') }}</h2>
                <p class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">{{ __('assistant.privacy_text') }}</p>
                @if($messages->isNotEmpty())
                    <form method="POST" action="{{ route('system.assistant.clear') }}" class="mt-4">
                        @csrf
                        <x-ui.button type="submit" variant="secondary" size="sm" icon="trash">{{ __('assistant.clear_history') }}</x-ui.button>
                    </form>
                @endif
            </x-ui.card>
        </div>
    </div>
</x-app.page>
@endsection
