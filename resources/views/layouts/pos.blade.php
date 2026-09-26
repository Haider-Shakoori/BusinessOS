@php
    $locale = app()->getLocale();
    $direction = App\Http\Middleware\SetLocale::direction($locale);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('pos.title') }} · {{ config('app.name') }}</title>
    <script>
        (function () {
            var stored = localStorage.getItem('bos-theme');
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored === 'dark' || (!stored && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @media print {
            .no-print { display: none !important; }
            .print-shell { box-shadow: none !important; border: 0 !important; margin: 0 !important; max-width: none !important; }
            body { background: #fff !important; }
        }
    </style>
</head>
<body class="min-h-screen bg-[#f3f6fb] font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">
    @yield('content')
</body>
</html>
