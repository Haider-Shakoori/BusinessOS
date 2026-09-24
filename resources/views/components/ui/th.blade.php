@props(['numeric' => false])

<th
    scope="col"
    {{ $attributes->merge(['class' =>
        'px-4 py-3 text-start text-[11px] font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400'
        .($numeric ? ' text-end' : '')
    ]) }}
>
    {{ $slot }}
</th>