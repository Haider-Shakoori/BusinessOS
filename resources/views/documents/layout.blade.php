@php
    $pdfMode = $pdfMode ?? false;
    $documentTitle = $documentTitle ?? config('app.name');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ config('localization.supported.'.app()->getLocale().'.direction', 'ltr') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $documentTitle }}</title>
    <style>
        :root {
            --accent: {{ $presentation['accent_color'] }};
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --paper: #ffffff;
            --page: #f1f5f9;
        }

        * { box-sizing: border-box; }
        html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body {
            margin: 0;
            background: {{ $pdfMode ? '#ffffff' : 'var(--page)' }};
            color: var(--ink);
            font-family: "DejaVu Sans", Arial, sans-serif;
            font-size: 13px;
            line-height: 1.45;
        }
        .document-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            max-width: 960px;
            margin: 18px auto 0;
            padding: 0 12px;
        }
        .document-toolbar-group { display: flex; flex-wrap: wrap; gap: 8px; }
        .document-toolbar a, .document-toolbar button {
            appearance: none;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            background: #fff;
            color: #334155;
            cursor: pointer;
            font: inherit;
            font-weight: 600;
            padding: 8px 12px;
            text-decoration: none;
        }
        .document-toolbar button,
        .document-toolbar .primary {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }
        .document-sheet {
            width: {{ $pdfMode ? '100%' : 'min(920px, calc(100% - 24px))' }};
            min-height: {{ $pdfMode ? '0' : '1180px' }};
            margin: {{ $pdfMode ? '0' : '14px auto 32px' }};
            background: var(--paper);
            box-shadow: {{ $pdfMode ? 'none' : '0 10px 35px rgba(15, 23, 42, .08)' }};
        }
        .pre-line { white-space: pre-line; }
        .money { white-space: nowrap; font-variant-numeric: tabular-nums; }
        .muted { color: var(--muted); }
        .numeric { text-align: end; }

        @page { size: A4; margin: 12mm; }

        @media print {
            body { background: #fff; font-size: 11px; }
            .document-toolbar { display: none !important; }
            .document-sheet {
                width: 100%;
                min-height: 0;
                margin: 0;
                box-shadow: none;
            }
            a { color: inherit; text-decoration: none; }
        }
    </style>
    @stack('document-styles')
</head>
<body>
    @unless ($pdfMode)
        <div class="document-toolbar">
            <div class="document-toolbar-group">
                <a href="{{ $backUrl ?? '#' }}">{{ __('documents.back') }}</a>
            </div>
            <div class="document-toolbar-group">
                <button type="button" onclick="window.print()">{{ __('documents.print') }}</button>
                @if (! empty($pdfUrl))
                    <a class="primary" href="{{ $pdfUrl }}" target="_blank" rel="noopener">{{ __('documents.pdf') }}</a>
                @endif
                @if (! empty($downloadUrl))
                    <a href="{{ $downloadUrl }}">{{ __('documents.download_pdf') }}</a>
                @endif
            </div>
        </div>
    @endunless

    <main class="document-sheet">
        @yield('document')
    </main>
</body>
</html>
