@props([
    'title' => null,
    'subtitle' => null,
    'breadcrumbs' => [],
])

<div class="space-y-6">
    @if (count($breadcrumbs))
        <div class="app-page-breadcrumbs mb-4">
            <x-ui.breadcrumb :items="$breadcrumbs" />
        </div>
    @endif

    <x-ui.page-header class="app-page-header" :title="$title" :description="$subtitle">
        @if (! empty($actions))
            <x-slot:actions>{{ $actions }}</x-slot:actions>
        @endif
    </x-ui.page-header>

    <div class="mt-6">{{ $slot }}</div>
</div>