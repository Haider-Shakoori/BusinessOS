@extends('documents.layout')

@php
    $logoSrc = $pdfMode ? ($presentation['logo_data_uri'] ?? null) : ($presentation['logo_url'] ?? null);
    $showAmount = static fn (?string $value): string => $value && ! \App\Support\Decimal::eq($value, '0') ? $value : '—';
@endphp

@push('document-styles')
<style>
    .statement-modern { padding: 40px; border-top: 7px solid var(--accent); }
    .sm-head, .sm-meta, .sm-totals { width: 100%; border-collapse: collapse; }
    .sm-head td, .sm-meta td, .sm-totals td { vertical-align: top; }
    .sm-logo { max-width: 170px; max-height: 64px; margin-bottom: 10px; }
    .sm-business { margin: 0; font-size: 23px; }
    .sm-title { color: var(--accent); font-size: 27px; font-weight: 800; text-align: end; text-transform: uppercase; }
    .sm-muted { color: var(--muted); font-size: 11px; }
    .sm-meta { margin-top: 26px; }
    .sm-meta td:first-child { width: 58%; padding-inline-end: 26px; }
    .sm-label { color: var(--accent); font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .sm-customer { margin-top: 6px; font-size: 16px; font-weight: 750; }
    .sm-facts { width: 100%; border-collapse: collapse; }
    .sm-facts td { padding: 3px 0; }
    .sm-facts td:first-child { color: var(--muted); }
    .sm-facts td:last-child { text-align: end; font-weight: 650; }
    .sm-table { width: 100%; margin-top: 28px; border-collapse: collapse; font-size: 11px; }
    .sm-table th { background: var(--accent); color: #fff; padding: 8px 6px; font-size: 9px; text-align: start; text-transform: uppercase; }
    .sm-table td { padding: 8px 6px; border-bottom: 1px solid var(--line); vertical-align: top; }
    .sm-table .numeric { text-align: end; }
    .sm-bf td { background: #f8fafc; font-weight: 650; }
    .sm-totals { margin-top: 24px; }
    .sm-totals td:first-child { width: 58%; }
    .sm-summary { width: 100%; border-collapse: collapse; }
    .sm-summary td { padding: 4px 0; }
    .sm-summary td:last-child { text-align: end; font-weight: 650; }
    .sm-summary .closing td { border-top: 2px solid var(--accent); padding-top: 9px; color: var(--accent); font-size: 15px; font-weight: 800; }
    .sm-footer { margin-top: 34px; border-top: 1px solid var(--line); padding-top: 10px; text-align: center; color: var(--muted); font-size: 10px; }
</style>
@endpush

@section('document')
<section class="statement-modern">
    <table class="sm-head"><tr>
        <td style="width:58%">
            @if ($logoSrc)<img class="sm-logo" src="{{ $logoSrc }}" alt="{{ $presentation['business']?->name }}">@endif
            <h2 class="sm-business">{{ $presentation['business']?->name }}</h2>
            <div class="sm-muted">
                @if ($presentation['profile']['address'])<div>{{ $presentation['profile']['address'] }}</div>@endif
                @if ($presentation['profile']['phone'])<div>{{ $presentation['profile']['phone'] }}</div>@endif
                @if ($presentation['profile']['email'])<div>{{ $presentation['profile']['email'] }}</div>@endif
            </div>
        </td>
        <td><div class="sm-title">{{ __('documents.statement') }}</div></td>
    </tr></table>

    @if ($presentation['header_text'])<div class="pre-line sm-muted" style="margin-top:16px">{{ $presentation['header_text'] }}</div>@endif

    <table class="sm-meta"><tr>
        <td>
            <div class="sm-label">{{ __('documents.bill_to') }}</div>
            <div class="sm-customer">{{ $customer->name }}</div>
            @if ($customer->company_name)<div class="sm-muted">{{ $customer->company_name }}</div>@endif
            @if ($customer->email)<div class="sm-muted">{{ $customer->email }}</div>@endif
            @if ($customer->phone)<div class="sm-muted">{{ $customer->phone }}</div>@endif
            @if ($customer->address)<div class="sm-muted pre-line">{{ $customer->address }}</div>@endif
        </td>
        <td><table class="sm-facts">
            <tr><td>{{ __('documents.period') }}</td><td>{{ $dateFrom ?: __('documents.all_dates') }} — {{ $dateTo ?: __('documents.all_dates') }}</td></tr>
            <tr><td>{{ __('settings.base_currency') }}</td><td>{{ $baseCurrency }}</td></tr>
            <tr><td>{{ __('customers.statement.as_of') }}</td><td>{{ now()->format($dateFormat) }}</td></tr>
        </table></td>
    </tr></table>

    <table class="sm-table">
        <thead><tr>
            <th>{{ __('customers.ledger.columns.date') }}</th>
            <th>{{ __('documents.type') }}</th>
            <th>{{ __('documents.reference') }}</th>
            <th>{{ __('documents.description') }}</th>
            <th class="numeric">{{ __('documents.debit') }}</th>
            <th class="numeric">{{ __('documents.credit') }}</th>
            <th class="numeric">{{ __('documents.balance') }}</th>
        </tr></thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="{{ $row['type'] === 'brought_forward' ? 'sm-bf' : '' }}">
                    <td>{{ $row['date'] ?: '—' }}</td>
                    <td>{{ __('customers.ledger.types.'.$row['type']) }}</td>
                    <td>{{ $row['reference'] ?: '—' }}</td>
                    <td>{{ $row['description'] ?: '—' }}</td>
                    <td class="numeric money">{{ $showAmount($row['base_debit'] ?? $row['debit']) }}</td>
                    <td class="numeric money">{{ $showAmount($row['base_credit'] ?? $row['credit']) }}</td>
                    <td class="numeric money">{{ $row['balance'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" style="padding:20px;text-align:center;color:var(--muted)">{{ __('customers.ledger.no_transactions') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="sm-totals"><tr>
        <td></td>
        <td><table class="sm-summary">
            <tr><td>{{ $broughtForward !== null ? __('documents.brought_forward') : __('documents.opening_balance') }}</td><td>{{ $broughtForward ?? $openingBalance }}</td></tr>
            <tr><td>{{ __('documents.period_debits') }}</td><td>{{ $periodDebits }}</td></tr>
            <tr><td>{{ __('documents.period_credits') }}</td><td>{{ $periodCredits }}</td></tr>
            <tr class="closing"><td>{{ __('documents.closing_balance') }}</td><td>{{ $closingBalance }}</td></tr>
        </table></td>
    </tr></table>

    @if ($presentation['footer_text'])<footer class="sm-footer pre-line">{{ $presentation['footer_text'] }}</footer>@endif
</section>
@endsection
