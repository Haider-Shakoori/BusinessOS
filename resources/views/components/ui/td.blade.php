@props(['numeric' => false])

<td
    {{ $attributes->merge(['class' =>
        'border-e border-slate-100 px-3.5 py-3 text-[12.5px] text-slate-700 last:border-e-0 dark:border-slate-800 dark:text-slate-200'
        .($numeric ? ' text-end tabular-nums text-slate-900 dark:text-slate-100' : '')
    ]) }}
>
    {{ $slot }}
</td>
