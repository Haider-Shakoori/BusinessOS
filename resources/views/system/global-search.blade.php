@extends('layouts.app')

@section('content')
<x-app.page icon="search" :title="__('system.search.title')" :subtitle="__('system.search.subtitle')">
    <x-ui.card>
        <form method="GET" action="{{ route('system.search.index') }}" class="flex flex-col gap-3 sm:flex-row">
            <div class="min-w-0 flex-1">
                <x-ui.search-input name="q" :label="__('system.search.title')" :placeholder="__('system.search.placeholder')" :value="$query" autofocus />
            </div>
            <div class="flex items-end"><x-ui.button type="submit" icon="search">{{ __('system.search.button') }}</x-ui.button></div>
        </form>
    </x-ui.card>

    <div class="mt-5">
        @if(mb_strlen($query) < 2)
            <x-ui.card><p class="text-sm text-slate-500">{{ __('system.search.hint') }}</p></x-ui.card>
        @elseif($results->isEmpty())
            <x-ui.card><p class="text-sm text-slate-500">{{ __('system.search.no_results') }}</p></x-ui.card>
        @else
            <x-ui.card>
                <x-slot:header><h2 class="font-semibold text-slate-900 dark:text-white">{{ __('system.search.results') }} · {{ $results->count() }}</h2></x-slot:header>
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach($results as $result)
                        <a href="{{ $result['url'] }}" class="flex items-center gap-3 px-1 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300"><x-ui.icon :name="$result['icon']" class="size-4" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-medium text-slate-900 dark:text-white">{{ $result['title'] }}</span>
                                <span class="block truncate text-xs text-slate-500">{{ $result['type'] }} @if($result['subtitle']) · {{ $result['subtitle'] }} @endif</span>
                            </span>
                            <x-ui.icon name="chevron-right" class="size-4 text-slate-400 rtl-flip" />
                        </a>
                    @endforeach
                </div>
            </x-ui.card>
        @endif
    </div>
</x-app.page>
@endsection
