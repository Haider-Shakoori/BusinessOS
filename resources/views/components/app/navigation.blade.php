@props(['items' => []])

<ul class="space-y-3">
    @foreach ($items as $group)
        <li>
            <p class="sr-only">{{ $group['label'] ?? '' }}</p>
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
