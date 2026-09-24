@props(['items' => []])

<ul class="space-y-6">
    @foreach ($items as $group)
        <li>
            @if (! empty($group['label']))
                <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500" x-bind:class="collapsed ? 'sr-only' : ''">
                    {{ $group['label'] }}
                </p>
            @endif
            <ul class="space-y-1">
                @foreach ($group['items'] as $item)
                    <li>
                        <x-app.nav-link :item="$item" />
                    </li>
                @endforeach
            </ul>
        </li>
    @endforeach
</ul>