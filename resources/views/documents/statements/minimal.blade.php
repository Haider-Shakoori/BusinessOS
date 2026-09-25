@extends('documents.layout')

@php
    $logoSrc = $pdfMode ? ($presentation['logo_data_uri'] ?? null) : ($presentation['logo_url'] ?? null);
    $showAmount = static fn (?string $value): string => $value && ! \App\Support\Decimal::eq($value, '0') ? $value : '—';
@endphp

@push('document-styles')
<style>
    .statement-minimal { padding: 46px 50px; }
    .stm-head, .stm-meta, .stm-summary-wrap { width: 100%; border-collapse: collapse; }
    .stm-head td, .stm-meta td, .stm-summary-wrap td { vertical-align: top; }
    .stm-head { border-bottom: 2px solid var(--ink); }
    .stm-logo { max-width: 110px; max-height: 54px; margin-bottom: 9px; }
    .stm-business { margin: 0; font-size: 19px; }
    .stm-title { text-align: end; font-size: 25px; font-weight: 500; text-transform: uppercase; }
    .stm-muted { color: var(--muted); font-size: 10px; }
    .stm-meta { margin-top: 26px; }
    .stm-meta td:first-child { width: 58%; padding-inline-end: 26px; }
    .stm-label { color: var(--muted); font-size: 9px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
    .stm-customer { margin-top: 5px; font-size: 15px; font-weight: 700; }
    .stm-facts, .stm-summary { width: 100%; border-collapse: collapse; }
    .stm-facts td, .stm-summary td { padding: 3px 0; }
    .stm-facts td:first-child { color: var(--muted); }
    .stm-facts td:last-child, .stm-summary td:last-child { text-align: end; font-weight: 600; }
    .stm-table { width: 100%; margin-top: 28px; border-collapse: collapse; font-size: 10px; }
    .stm-table th { border-bottom: 1px solid var(--ink); padding: 7px 4px; color: var(--muted); font-size: 9px; text-align: start; text-transform: uppercase; }
    .stm-table td { border-bottom: 1px solid var(--line); padding: 7px 4px; vertical-align: top; }
    .stm-table .numeric { text-align: end; }
    .stm-bf td { background: #f8fafc; font-weight: 650; }
    .stm-summary-wrap { margin-top: 22px; }
    .stm-summary-wrap > tbody > tr > td:first-child { width: 60%; }
    .stm-summary .closing td { border-top: 1px solid var(--ink); padding-top: 8px; color: var(--accent); font-size: 14px; font-weight: 800; }
    .stm-footer { margin-top: 36px; border-top: 1px solid var(--line); padding-top: 9px; text-align: center; color: var(--muted); font-size: 9px; }
</style>
@endpush

@section('document')
<section class="statement-minimal">
    <table class="stm-head"><tr>
        <td style="width:58%">
            @if ($logoSrc)<img class="stm-logo" src="{{ $logoSrc }}" alt="{{ $presentation['business']?->name }}">@endif
            <h2 class="stm-business">{{ $presentation['business']?->name }}</h2>
            <div class="stm-muted">
                @if ($presentation['profile']['address'])<div>{{ $presentation['profile']['address'] }}</div>@endif
                @if ($presentation['profile']['phone'])<div>{{ $presentation['profile']['phone'] }}</div>@endif
                @if ($presentation['profile']['email'])<div>{{ $presentation['profile']['email'] }}</div>@endif
            </div>
        </td>
        <td><div class="stm-title">{{ __('documents.statement') }}</div></td>
    </tr></table>

    <table class="stm-meta"><tr>
        <td>
            <div class="stm-label">{{ __('documents.bill_to') }}</div>
            <div class="stm-customer">{{ $customer->name }}</div>
            @if ($customer->company_name)<div class="stm-muted">{{ $customer->company_name }}</div>@endif
            @if ($customer->address)<div class="stm-muted pre-line">{{ $customer->address }}</div>@endif
        </td>
        <td><table class="stm-facts">
            <tr><td>{{ __('documents.period') }}</td><td>{{ $dateFrom ?: __('documents.all_dates') }} — {{ $dateTo ?: __('documents.all_dates') }}</td></tr>
            <tr><td>{{ __('settings.base_currency') }}</td><td>{{ $baseCurrency }}</td></tr>
            <tr><td>{{ __('customers.statement.as_of') }}</td><td>{{ now()->format($dateFormat) }}</td></tr>
        </table></td>
    </tr></table>

    <table class="stm-table">
        <thead><tr>
            <th>{{ __('customers.ledger.columns.date') }}</th><th>{{ __('documents.type') }}</th><th>{{ __('documents.reference') }}</th><th>{{ __('documents.description') }}</th>
            <th class="numeric">{{ __('documents.debit') }}</th><th class="numeric">{{ __('documents.credit') }}</th><th class="numeric">{{ __('documents.balance') }}</th>
        </tr></thead>
        <tbody>
            @forelse($rows as $row)<tr class="{{ $row['type'] === 'brought_forward' ? 'stm-bf' : '' }}">
                <td>{{ $row['date'] ?: '—' }}</td><td>{{ __('customers.ledger.types.'.$row['type']) }}</td><td>{{ $row['reference'] ?: '—' }}</td><td>{{ $row['description'] ?: '—' }}</td>
                <td class="numeric money">{{ $showAmount($row['base_debit'] ?? $row['debit']) }}</td>
                <td class="numeric money">{{ $showAmount($row['base_credit'] ?? $row['credit']) }}</td>
                <td class="numeric money">{{ $row['balance'] }}</td>
            </tr>@empty<tr><td colspan="7" style="padding:20px;text-align:center;color:var(--muted)">{{ __('customers.ledger.no_transactions') }}</td></tr>@endforelse
        </tbody>
    </table>

    <table class="stm-summary-wrap"><tr><td></td><td><table class="stm-summary">
        <tr><td>{{ $broughtForward !== null ? __('documents.brought_forward') : __('documents.opening_balance') }}</td><td>{{ $broughtForward ?? $openingBalance }}</td></tr>
        <tr><td>{{ __('documents.period_debits') }}</td><td>{{ $periodDebits }}</td></tr>
        <tr><td>{{ __('documents.period_credits') }}</td><td>{{ $periodCredits }}</td></tr>
        <tr class="closing"><td>{{ __('documents.closing_balance') }}</td><td>{{ $closingBalance }}</td></tr>
    </table></td></tr></table>

    @if ($presentation['footer_text'])<footer class="stm-footer pre-line">{{ $presentation['footer_text'] }}</footer>@endif
</section>
@endsection
