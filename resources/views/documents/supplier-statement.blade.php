<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), ['fa','ar'], true) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle }}</title>
    <style>
        @page { margin: 24px; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; margin: 0; background: #fff; }
        .toolbar { display: flex; gap: 8px; justify-content: flex-end; margin-bottom: 18px; }
        .toolbar a,.toolbar button { padding: 8px 12px; border: 1px solid #cbd5e1; background: #fff; border-radius: 6px; color: #0f172a; text-decoration: none; cursor: pointer; }
        .header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 2px solid #2563eb; padding-bottom: 14px; margin-bottom: 18px; }
        h1 { font-size: 22px; margin: 0; }
        .muted { color: #64748b; }
        .summary { width: 100%; margin: 12px 0 18px; border-collapse: collapse; }
        .summary td { padding: 7px 10px; background: #f8fafc; border: 1px solid #e2e8f0; }
        table.ledger { width: 100%; border-collapse: collapse; }
        .ledger th { background: #f1f5f9; text-align: left; padding: 7px; border-bottom: 1px solid #cbd5e1; }
        .ledger td { padding: 7px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .totals { margin-top: 16px; width: 44%; margin-left: auto; border-collapse: collapse; }
        .totals td { padding: 6px; border-bottom: 1px solid #e2e8f0; }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
@if(!$pdfMode)
<div class="toolbar">
    <a href="{{ $backUrl }}">{{ __('actions.back') }}</a>
    <button type="button" onclick="window.print()">{{ __('suppliers.statement.print') }}</button>
    <a href="{{ $pdfUrl }}">{{ __('suppliers.statement.pdf') }}</a>
    <a href="{{ $downloadUrl }}">{{ __('suppliers.statement.download') }}</a>
</div>
@endif

<div class="header">
    <div>
        <h1>{{ __('suppliers.statement.title') }}</h1>
        <div class="muted">{{ $supplier->name }} @if($supplier->code) · {{ $supplier->code }} @endif</div>
        @if($supplier->address)<div class="muted">{{ $supplier->address }}</div>@endif
    </div>
    <div>
        <div><strong>{{ __('suppliers.statement.period') }}:</strong></div>
        <div class="muted">
            @if($dateFrom || $dateTo)
                {{ $dateFrom ?: '…' }} — {{ $dateTo ?: '…' }}
            @else
                {{ __('suppliers.statement.all_time') }}
            @endif
        </div>
        <div class="muted">{{ $baseCurrency }}</div>
    </div>
</div>

<table class="summary">
    <tr>
        <td><strong>{{ __('suppliers.summary.opening') }}</strong><br>{{ $summary['opening_balance'] }}</td>
        <td><strong>{{ __('suppliers.summary.purchases') }}</strong><br>{{ $summary['total_purchases'] }}</td>
        <td><strong>{{ __('suppliers.summary.returns') }}</strong><br>{{ $summary['total_returns'] }}</td>
        <td><strong>{{ __('suppliers.summary.paid') }}</strong><br>{{ $summary['total_paid'] }}</td>
    </tr>
</table>

<table class="ledger">
    <thead>
        <tr>
            <th>{{ __('suppliers.ledger.columns.date') }}</th>
            <th>{{ __('suppliers.ledger.columns.type') }}</th>
            <th>{{ __('suppliers.ledger.columns.reference') }}</th>
            <th>{{ __('suppliers.ledger.columns.description') }}</th>
            <th class="num">{{ __('suppliers.ledger.columns.debit') }}</th>
            <th class="num">{{ __('suppliers.ledger.columns.credit') }}</th>
            <th class="num">{{ __('suppliers.ledger.columns.balance') }}</th>
        </tr>
    </thead>
    <tbody>
    @forelse($rows as $row)
        <tr>
            <td>{{ $row['date'] ?: '—' }}</td>
            <td>{{ __('suppliers.ledger.'.(($row['type'] ?? '') === 'payment' ? 'payment_type' : ($row['type'] ?? 'opening'))) }}</td>
            <td>{{ $row['reference'] ?: '—' }}</td>
            <td>{{ $row['description'] ?: '—' }}</td>
            <td class="num">{{ $row['debit'] }}</td>
            <td class="num">{{ $row['credit'] }}</td>
            <td class="num">{{ $row['balance'] }}</td>
        </tr>
    @empty
        <tr><td colspan="7">{{ __('suppliers.ledger.empty') }}</td></tr>
    @endforelse
    </tbody>
</table>

<table class="totals">
    <tr><td>{{ __('suppliers.statement.debits') }}</td><td class="num">{{ $periodDebits }}</td></tr>
    <tr><td>{{ __('suppliers.statement.credits') }}</td><td class="num">{{ $periodCredits }}</td></tr>
    <tr><td><strong>{{ __('suppliers.statement.closing') }}</strong></td><td class="num"><strong>{{ $closingBalance }} {{ $baseCurrency }}</strong></td></tr>
</table>
</body>
</html>
