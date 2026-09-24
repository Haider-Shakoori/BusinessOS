@props(['numeric' => false])

<th
    scope="col"
    {{ $attributes->merge(['class' =>
        'whitespace-nowrap border-e border-slate-200/70 px-3.5 py-3 text-start text-[11px] font-semibold tracking-[0.01em] text-slate-700 last:border-e-0 dark:border-slate-700 dark:text-slate-300'
        .($numeric ? ' text-end' : '')
    ]) }}
>
    {{ $slot }}
</th>
