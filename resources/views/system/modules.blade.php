@extends('layouts.app')

@section('content')
<x-app.page icon="module-grid" :title="__('system.modules.title')" :subtitle="__('system.modules.subtitle')">
    @if (session('status')) <div class="mb-5"><x-ui.alert type="success">{{ session('status') }}</x-ui.alert></div> @endif
    @if ($errors->any()) <div class="mb-5"><x-ui.alert type="danger">{{ $errors->first() }}</x-ui.alert></div> @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach($modules as $key => $module)
            @php
                $isEnabled = in_array($key, $enabled, true);
                $isCore = in_array($key, $coreModules, true);
            @endphp
            <x-ui.card>
                <div class="flex h-full flex-col">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="grid size-10 place-items-center rounded-lg bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200"><x-ui.icon :name="$module['icon'] ?? 'cube'" class="size-5" /></span>
                            <div>
                                <h3 class="font-semibold text-slate-900 dark:text-white">{{ __($module['label'] ?? 'modules.'.$key) }}</h3>
                                <p class="text-xs text-slate-500">{{ $key }}</p>
                            </div>
                        </div>
                        <x-ui.badge :tone="$isEnabled ? 'success' : 'neutral'">{{ $isEnabled ? __('system.modules.enabled') : __('system.modules.disabled') }}</x-ui.badge>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        @if($isCore)<x-ui.badge tone="brand">{{ __('system.modules.core') }}</x-ui.badge>@endif
                        @if(isset($module['permission']))<x-ui.badge tone="neutral">{{ $module['permission'] }}</x-ui.badge>@endif
                    </div>

                    @can('modules.manage')
                    <div class="mt-auto pt-5">
                        <form method="POST" action="{{ route('system.modules.toggle') }}">
                            @csrf
                            <input type="hidden" name="module_key" value="{{ $key }}">
                            <input type="hidden" name="enabled" value="{{ $isEnabled ? 0 : 1 }}">
                            <x-ui.button type="submit" class="w-full justify-center" :variant="$isEnabled ? 'secondary' : 'primary'" :disabled="$isCore && $isEnabled">
                                {{ $isEnabled ? __('system.modules.disable') : __('system.modules.enable') }}
                            </x-ui.button>
                        </form>
                    </div>
                    @endcan
                </div>
            </x-ui.card>
        @endforeach
    </div>
</x-app.page>
@endsection
