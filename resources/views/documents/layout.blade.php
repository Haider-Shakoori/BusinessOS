<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ config('localization.supported.'.app()->getLocale().'.direction', 'ltr') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->invoice_number }} — {{ __('documents.invoice') }}</title>
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
            background: var(--page);
            color: var(--ink);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
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
        .document-toolbar button {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }
        .document-sheet {
            width: min(920px, calc(100% - 24px));
            min-height: 1180px;
            margin: 14px auto 32px;
            background: var(--paper);
            box-shadow: 0 10px 35px rgba(15, 23, 42, .08);
        }
        .pre-line { white-space: pre-line; }
        .money { white-space: nowrap; font-variant-numeric: tabular-nums; }
        .muted { color: var(--muted); }

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
    <div class="document-toolbar">
        <a href="{{ route('invoices.show', $invoice) }}">{{ __('documents.back_to_invoice') }}</a>
        <button type="button" onclick="window.print()">{{ __('documents.print') }}</button>
    </div>

    <main class="document-sheet">
        @yield('document')
    </main>
</body>
</html>
