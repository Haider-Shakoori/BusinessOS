@props([
    'title' => null,
    'subtitle' => null,
    'breadcrumbs' => [],
    'icon' => null,
])

<div class="space-y-5">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <x-ui.page-header class="app-page-header flex-1" :title="$title" :description="$subtitle" :icon="$icon">
            @if (! empty($actions))
                <x-slot:actions>{{ $actions }}</x-slot:actions>
            @endif
        </x-ui.page-header>

        @if (count($breadcrumbs))
            <div class="app-page-breadcrumbs pt-1 lg:pt-2">
                <x-ui.breadcrumb :items="$breadcrumbs" />
            </div>
        @endif
    </div>

    <div>{{ $slot }}</div>
</div>
