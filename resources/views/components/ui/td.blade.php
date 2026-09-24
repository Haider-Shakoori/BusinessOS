@props(['numeric' => false])

<td
    {{ $attributes->merge(['class' =>
        'px-4 py-3 text-sm text-gray-700 dark:text-gray-200'
        .($numeric ? ' text-end tabular-nums text-gray-900 dark:text-gray-100' : '')
    ]) }}
>
    {{ $slot }}
</td>