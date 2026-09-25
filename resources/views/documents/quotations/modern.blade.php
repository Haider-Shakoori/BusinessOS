@extends('documents.layout')

@php
    $logoSrc = $pdfMode ? ($presentation['logo_data_uri'] ?? null) : ($presentation['logo_url'] ?? null);
    $terms = $quotation->terms ?: $presentation['terms'];
@endphp

@push('document-styles')
<style>
    .quote-modern { padding: 42px; border-top: 7px solid var(--accent); }
    .quote-head, .quote-meta, .quote-summary { width: 100%; border-collapse: collapse; }
    .quote-head td, .quote-meta td, .quote-summary td { vertical-align: top; }
    .quote-logo { max-width: 180px; max-height: 68px; object-fit: contain; margin-bottom: 12px; }
    .quote-business { margin: 0; font-size: 24px; }
    .quote-title { color: var(--accent); font-size: 31px; font-weight: 800; text-align: end; text-transform: uppercase; }
    .quote-number { margin-top: 4px; text-align: end; font-size: 14px; font-weight: 700; }
    .quote-profile, .quote-subtle { color: var(--muted); font-size: 11px; }
    .quote-note { margin-top: 22px; padding: 10px 13px; border-inline-start: 3px solid var(--accent); background: #f8fafc; }
    .quote-meta { margin-top: 28px; }
    .quote-meta td:first-child { width: 58%; padding-inline-end: 24px; }
    .quote-label { color: var(--accent); font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .quote-customer { margin-top: 6px; font-size: 16px; font-weight: 750; }
    .quote-facts { width: 100%; border-collapse: collapse; }
    .quote-facts td { padding: 3px 0; }
    .quote-facts td:first-child { color: var(--muted); }
    .quote-facts td:last-child { text-align: end; font-weight: 650; }
    .quote-table { width: 100%; margin-top: 28px; border-collapse: collapse; }
    .quote-table th { background: var(--accent); color: #fff; padding: 9px 8px; font-size: 10px; text-align: start; text-transform: uppercase; }
    .quote-table td { padding: 10px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
    .quote-table .numeric { text-align: end; }
    .quote-summary { margin-top: 24px; }
    .quote-summary > tbody > tr > td:first-child { width: 58%; padding-inline-end: 28px; }
    .quote-totals { width: 100%; border-collapse: collapse; }
    .quote-totals td { padding: 4px 0; }
    .quote-totals td:last-child { text-align: end; font-weight: 650; }
    .quote-totals .grand td { border-top: 2px solid var(--accent); padding-top: 9px; color: var(--accent); font-size: 16px; font-weight: 800; }
    .quote-section { margin-top: 28px; padding-top: 10px; border-top: 1px solid var(--line); }
    .quote-section h3 { margin: 0 0 5px; color: var(--accent); font-size: 10px; text-transform: uppercase; }
    .quote-signature { width: 230px; margin-top: 38px; margin-inline-start: auto; border-top: 1px solid #94a3b8; padding-top: 6px; text-align: center; color: var(--muted); }
    .quote-footer { margin-top: 34px; border-top: 1px solid var(--line); padding-top: 10px; text-align: center; color: var(--muted); font-size: 10px; }
</style>
@endpush

@section('document')
<section class="quote-modern">
    <table class="quote-head">
        <tr>
            <td style="width:58%">
                @if ($logoSrc)<img class="quote-logo" src="{{ $logoSrc }}" alt="{{ $presentation['business']?->name }}">@endif
                <h2 class="quote-business">{{ $presentation['business']?->name }}</h2>
                <div class="quote-profile">
                    @if ($presentation['profile']['address'])<div>{{ $presentation['profile']['address'] }}</div>@endif
                    @if ($presentation['profile']['phone'])<div>{{ $presentation['profile']['phone'] }}</div>@endif
                    @if ($presentation['profile']['email'])<div>{{ $presentation['profile']['email'] }}</div>@endif
                </div>
            </td>
            <td>
                <div class="quote-title">{{ __('documents.quotation') }}</div>
                <div class="quote-number">{{ $quotation->quotation_number }}</div>
            </td>
        </tr>
    </table>

    @if ($presentation['header_text'])<div class="quote-note pre-line">{{ $presentation['header_text'] }}</div>@endif

    <table class="quote-meta">
        <tr>
            <td>
                <div class="quote-label">{{ __('documents.bill_to') }}</div>
                <div class="quote-customer">{{ $quotation->customer?->name ?? __('documents.no_customer') }}</div>
                @if ($quotation->customer?->company_name)<div class="quote-subtle">{{ $quotation->customer->company_name }}</div>@endif
                @if ($quotation->customer?->address)<div class="quote-subtle pre-line">{{ $quotation->customer->address }}</div>@endif
                @if ($quotation->customer?->phone)<div class="quote-subtle">{{ $quotation->customer->phone }}</div>@endif
                @if ($quotation->customer?->email)<div class="quote-subtle">{{ $quotation->customer->email }}</div>@endif
            </td>
            <td>
                <table class="quote-facts">
                    <tr><td>{{ __('documents.issued_on') }}</td><td>{{ $quotation->date?->format($dateFormat) }}</td></tr>
                    <tr><td>{{ __('documents.valid_until') }}</td><td>{{ $quotation->expiry_date?->format($dateFormat) ?? '—' }}</td></tr>
                    <tr><td>{{ __('documents.status') }}</td><td>{{ __('quotations.statuses.'.$quotation->status->value) }}</td></tr>
                    <tr><td>{{ __('documents.created_by') }}</td><td>{{ $quotation->createdBy?->name ?? '—' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="quote-table">
        <thead><tr>
            <th>{{ __('documents.description') }}</th>
            <th class="numeric">{{ __('documents.quantity') }}</th>
            <th class="numeric">{{ __('documents.unit_price') }}</th>
            @if ((float) $quotation->tax_amount > 0)<th class="numeric">{{ __('documents.tax') }}</th>@endif
            <th class="numeric">{{ __('documents.amount') }}</th>
        </tr></thead>
        <tbody>
            @foreach ($quotation->items as $item)
                <tr>
                    <td>{{ $item->description }} @if($item->product)<div class="quote-subtle">{{ $item->product->name }}</div>@endif</td>
                    <td class="numeric money">{{ $item->quantity }}</td>
                    <td class="numeric money">{{ $quotation->currency_code }} {{ number_format((float) $item->unit_price, 2) }}</td>
                    @if ((float) $quotation->tax_amount > 0)<td class="numeric">{{ $item->tax_rate !== null ? $item->tax_rate.'%' : '—' }}</td>@endif
                    <td class="numeric money">{{ $quotation->currency_code }} {{ number_format((float) $item->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="quote-summary"><tr>
        <td>
            @if ($quotation->notes)
                <div class="quote-label">{{ __('quotations.notes') }}</div>
                <div class="pre-line quote-subtle">{{ $quotation->notes }}</div>
            @endif
        </td>
        <td>
            <table class="quote-totals">
                <tr><td>{{ __('documents.subtotal') }}</td><td>{{ $quotation->currency_code }} {{ number_format((float) $quotation->subtotal, 2) }}</td></tr>
                @if ((float) $quotation->discount_amount > 0)<tr><td>{{ __('documents.discount') }}</td><td>− {{ $quotation->currency_code }} {{ number_format((float) $quotation->discount_amount, 2) }}</td></tr>@endif
                @if ((float) $quotation->tax_amount > 0)<tr><td>{{ __('documents.tax') }}</td><td>{{ $quotation->currency_code }} {{ number_format((float) $quotation->tax_amount, 2) }}</td></tr>@endif
                <tr class="grand"><td>{{ __('documents.total') }}</td><td>{{ $quotation->currency_code }} {{ number_format((float) $quotation->total, 2) }}</td></tr>
                @if ($quotation->currency_code !== $baseCurrency)
                    <tr><td>{{ __('documents.base_amount', ['currency' => $baseCurrency]) }}</td><td>{{ $baseCurrency }} {{ number_format((float) $quotation->base_amount, 2) }}</td></tr>
                    <tr><td>{{ __('documents.exchange_rate') }}</td><td>{{ $quotation->exchange_rate }}</td></tr>
                @endif
            </table>
        </td>
    </tr></table>

    @if ($terms)<section class="quote-section"><h3>{{ __('documents.terms') }}</h3><div class="pre-line quote-subtle">{{ $terms }}</div></section>@endif
    @if ($presentation['bank_details'])<section class="quote-section"><h3>{{ __('documents.bank_details') }}</h3><div class="pre-line quote-subtle">{{ $presentation['bank_details'] }}</div></section>@endif
    @if ($presentation['signature_line'])<div class="quote-signature">{{ $presentation['signature_line'] }}</div>@endif
    @if ($presentation['footer_text'])<footer class="quote-footer pre-line">{{ $presentation['footer_text'] }}</footer>@endif
</section>
@endsection
