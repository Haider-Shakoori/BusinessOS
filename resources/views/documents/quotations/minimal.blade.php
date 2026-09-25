@extends('documents.layout')

@php
    $logoSrc = $pdfMode ? ($presentation['logo_data_uri'] ?? null) : ($presentation['logo_url'] ?? null);
    $terms = $quotation->terms ?: $presentation['terms'];
@endphp

@push('document-styles')
<style>
    .quote-minimal { padding: 48px 50px; }
    .qm-head, .qm-meta, .qm-summary { width: 100%; border-collapse: collapse; }
    .qm-head td, .qm-meta td, .qm-summary td { vertical-align: top; }
    .qm-head { border-bottom: 2px solid var(--ink); padding-bottom: 20px; }
    .qm-logo { max-width: 120px; max-height: 58px; margin-bottom: 10px; }
    .qm-business { margin: 0; font-size: 20px; }
    .qm-title { text-align: end; font-size: 27px; font-weight: 500; text-transform: uppercase; }
    .qm-number { text-align: end; color: var(--muted); font-weight: 650; }
    .qm-subtle { color: var(--muted); font-size: 11px; }
    .qm-meta { margin-top: 28px; }
    .qm-meta td:first-child { width: 58%; padding-inline-end: 26px; }
    .qm-label { color: var(--muted); font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
    .qm-customer { margin-top: 6px; font-size: 15px; font-weight: 700; }
    .qm-facts, .qm-totals { width: 100%; border-collapse: collapse; }
    .qm-facts td, .qm-totals td { padding: 3px 0; }
    .qm-facts td:first-child { color: var(--muted); }
    .qm-facts td:last-child, .qm-totals td:last-child { text-align: end; font-weight: 600; }
    .qm-table { width: 100%; margin-top: 30px; border-collapse: collapse; }
    .qm-table th { border-bottom: 1px solid var(--ink); padding: 7px 5px; color: var(--muted); font-size: 10px; text-align: start; text-transform: uppercase; }
    .qm-table td { border-bottom: 1px solid var(--line); padding: 10px 5px; vertical-align: top; }
    .qm-table .numeric { text-align: end; }
    .qm-summary { margin-top: 24px; }
    .qm-summary > tbody > tr > td:first-child { width: 58%; padding-inline-end: 32px; }
    .qm-totals .grand td { border-top: 1px solid var(--ink); padding-top: 9px; font-size: 16px; font-weight: 800; }
    .qm-section { margin-top: 26px; max-width: 75%; }
    .qm-section h3 { margin: 0 0 5px; font-size: 10px; text-transform: uppercase; }
    .qm-signature { width: 220px; margin-top: 42px; margin-inline-start: auto; border-top: 1px solid #94a3b8; padding-top: 6px; text-align: center; color: var(--muted); }
    .qm-footer { margin-top: 38px; border-top: 1px solid var(--line); padding-top: 9px; text-align: center; color: var(--muted); font-size: 10px; }
</style>
@endpush

@section('document')
<section class="quote-minimal">
    <table class="qm-head"><tr>
        <td style="width:58%">
            @if ($logoSrc)<img class="qm-logo" src="{{ $logoSrc }}" alt="{{ $presentation['business']?->name }}">@endif
            <h2 class="qm-business">{{ $presentation['business']?->name }}</h2>
            <div class="qm-subtle">
                @if ($presentation['profile']['address'])<div>{{ $presentation['profile']['address'] }}</div>@endif
                @if ($presentation['profile']['phone'])<div>{{ $presentation['profile']['phone'] }}</div>@endif
                @if ($presentation['profile']['email'])<div>{{ $presentation['profile']['email'] }}</div>@endif
            </div>
        </td>
        <td><div class="qm-title">{{ __('documents.quotation') }}</div><div class="qm-number">{{ $quotation->quotation_number }}</div></td>
    </tr></table>

    @if ($presentation['header_text'])<div class="pre-line qm-subtle" style="margin-top:16px;font-style:italic">{{ $presentation['header_text'] }}</div>@endif

    <table class="qm-meta"><tr>
        <td>
            <div class="qm-label">{{ __('documents.bill_to') }}</div>
            <div class="qm-customer">{{ $quotation->customer?->name ?? __('documents.no_customer') }}</div>
            @if ($quotation->customer?->company_name)<div class="qm-subtle">{{ $quotation->customer->company_name }}</div>@endif
            @if ($quotation->customer?->address)<div class="qm-subtle pre-line">{{ $quotation->customer->address }}</div>@endif
        </td>
        <td><table class="qm-facts">
            <tr><td>{{ __('documents.issued_on') }}</td><td>{{ $quotation->date?->format($dateFormat) }}</td></tr>
            <tr><td>{{ __('documents.valid_until') }}</td><td>{{ $quotation->expiry_date?->format($dateFormat) ?? '—' }}</td></tr>
            <tr><td>{{ __('documents.status') }}</td><td>{{ __('quotations.statuses.'.$quotation->status->value) }}</td></tr>
            <tr><td>{{ __('documents.created_by') }}</td><td>{{ $quotation->createdBy?->name ?? '—' }}</td></tr>
        </table></td>
    </tr></table>

    <table class="qm-table">
        <thead><tr>
            <th>{{ __('documents.description') }}</th><th class="numeric">{{ __('documents.quantity') }}</th><th class="numeric">{{ __('documents.unit_price') }}</th>
            @if ((float) $quotation->tax_amount > 0)<th class="numeric">{{ __('documents.tax') }}</th>@endif
            <th class="numeric">{{ __('documents.amount') }}</th>
        </tr></thead>
        <tbody>@foreach($quotation->items as $item)<tr>
            <td>{{ $item->description }} @if($item->product)<div class="qm-subtle">{{ $item->product->name }}</div>@endif</td>
            <td class="numeric money">{{ $item->quantity }}</td>
            <td class="numeric money">{{ $quotation->currency_code }} {{ number_format((float) $item->unit_price, 2) }}</td>
            @if ((float) $quotation->tax_amount > 0)<td class="numeric">{{ $item->tax_rate !== null ? $item->tax_rate.'%' : '—' }}</td>@endif
            <td class="numeric money">{{ $quotation->currency_code }} {{ number_format((float) $item->line_total, 2) }}</td>
        </tr>@endforeach</tbody>
    </table>

    <table class="qm-summary"><tr>
        <td>@if($quotation->notes)<div class="qm-label">{{ __('quotations.notes') }}</div><div class="pre-line qm-subtle">{{ $quotation->notes }}</div>@endif</td>
        <td><table class="qm-totals">
            <tr><td>{{ __('documents.subtotal') }}</td><td>{{ $quotation->currency_code }} {{ number_format((float) $quotation->subtotal, 2) }}</td></tr>
            @if ((float) $quotation->discount_amount > 0)<tr><td>{{ __('documents.discount') }}</td><td>− {{ $quotation->currency_code }} {{ number_format((float) $quotation->discount_amount, 2) }}</td></tr>@endif
            @if ((float) $quotation->tax_amount > 0)<tr><td>{{ __('documents.tax') }}</td><td>{{ $quotation->currency_code }} {{ number_format((float) $quotation->tax_amount, 2) }}</td></tr>@endif
            <tr class="grand"><td>{{ __('documents.total') }}</td><td>{{ $quotation->currency_code }} {{ number_format((float) $quotation->total, 2) }}</td></tr>
            @if ($quotation->currency_code !== $baseCurrency)
                <tr><td>{{ __('documents.base_amount', ['currency' => $baseCurrency]) }}</td><td>{{ $baseCurrency }} {{ number_format((float) $quotation->base_amount, 2) }}</td></tr>
                <tr><td>{{ __('documents.exchange_rate') }}</td><td>{{ $quotation->exchange_rate }}</td></tr>
            @endif
        </table></td>
    </tr></table>

    @if ($terms)<section class="qm-section"><h3>{{ __('documents.terms') }}</h3><div class="pre-line qm-subtle">{{ $terms }}</div></section>@endif
    @if ($presentation['bank_details'])<section class="qm-section"><h3>{{ __('documents.bank_details') }}</h3><div class="pre-line qm-subtle">{{ $presentation['bank_details'] }}</div></section>@endif
    @if ($presentation['signature_line'])<div class="qm-signature">{{ $presentation['signature_line'] }}</div>@endif
    @if ($presentation['footer_text'])<footer class="qm-footer pre-line">{{ $presentation['footer_text'] }}</footer>@endif
</section>
@endsection
