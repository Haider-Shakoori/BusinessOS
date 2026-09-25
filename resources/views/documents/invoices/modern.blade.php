@extends('documents.layout')

@php
    $logoSrc = $pdfMode ? ($presentation['logo_data_uri'] ?? null) : ($logoSrc ?? null);
@endphp

@push('document-styles')
<style>
    .modern { position: relative; overflow: hidden; padding: 48px; }
    .modern::before { content: ""; position: absolute; inset: 0 0 auto; height: 7px; background: var(--accent); }
    .modern-head { display: flex; justify-content: space-between; gap: 36px; align-items: flex-start; }
    .modern-brand { max-width: 56%; }
    .modern-logo { max-width: 180px; max-height: 72px; object-fit: contain; margin-bottom: 14px; }
    .modern-business { margin: 0; font-size: 25px; line-height: 1.15; }
    .modern-profile { margin-top: 8px; color: var(--muted); font-size: 12px; }
    .modern-title { text-align: end; }
    .modern-title h1 { margin: 0; color: var(--accent); font-size: 34px; letter-spacing: .02em; text-transform: uppercase; }
    .modern-number { margin-top: 5px; font-size: 15px; font-weight: 700; }
    .modern-header-note { margin-top: 28px; border-inline-start: 3px solid var(--accent); padding: 10px 14px; background: #f8fafc; color: #475569; }
    .modern-meta { display: grid; grid-template-columns: 1.5fr 1fr; gap: 28px; margin-top: 34px; }
    .modern-box-label { margin-bottom: 7px; color: var(--accent); font-size: 10px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
    .modern-customer-name { font-size: 16px; font-weight: 750; }
    .modern-facts { display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; align-content: start; }
    .modern-facts dt { color: var(--muted); }
    .modern-facts dd { margin: 0; font-weight: 650; text-align: end; }
    .modern-table { width: 100%; margin-top: 34px; border-collapse: collapse; }
    .modern-table th { background: var(--accent); color: #fff; padding: 10px 9px; font-size: 10px; letter-spacing: .04em; text-align: start; text-transform: uppercase; }
    .modern-table td { border-bottom: 1px solid var(--line); padding: 11px 9px; vertical-align: top; }
    .modern-table .numeric { text-align: end; }
    .modern-product { color: var(--muted); font-size: 10px; margin-top: 2px; }
    .modern-summary { display: grid; grid-template-columns: 1fr minmax(260px, 38%); gap: 36px; margin-top: 26px; }
    .modern-notes { min-width: 0; }
    .modern-totals { width: 100%; border-collapse: collapse; }
    .modern-totals td { padding: 5px 0; }
    .modern-totals td:last-child { text-align: end; font-weight: 650; }
    .modern-totals .grand td { border-top: 2px solid var(--accent); padding-top: 10px; color: var(--accent); font-size: 17px; font-weight: 800; }
    .modern-totals .due td { padding-top: 8px; font-weight: 800; }
    .modern-sections { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; margin-top: 34px; }
    .modern-section { border-top: 1px solid var(--line); padding-top: 12px; min-height: 72px; }
    .modern-section h3 { margin: 0 0 6px; color: var(--accent); font-size: 11px; text-transform: uppercase; }
    .modern-signature { display: flex; justify-content: flex-end; margin-top: 42px; }
    .modern-signature-line { width: 230px; border-top: 1px solid #94a3b8; padding-top: 7px; text-align: center; color: var(--muted); }
    .modern-footer { margin-top: 42px; border-top: 1px solid var(--line); padding-top: 12px; color: var(--muted); text-align: center; font-size: 10px; }

    @media (max-width: 700px) {
        .modern { padding: 28px 22px; }
        .modern-head, .modern-meta, .modern-summary, .modern-sections { grid-template-columns: 1fr; display: grid; }
        .modern-brand { max-width: 100%; }
        .modern-title { text-align: start; }
        .modern-table { font-size: 11px; }
    }
    @if ($pdfMode)
        .modern { padding: 20px 16px; }
        .modern-head, .modern-meta, .modern-summary { display: table; width: 100%; table-layout: fixed; }
        .modern-brand, .modern-title, .modern-meta > div, .modern-meta > dl, .modern-notes, .modern-totals { display: table-cell; vertical-align: top; }
        .modern-brand { width: 58%; }
        .modern-title { width: 42%; }
        .modern-meta > div { width: 58%; }
        .modern-meta > dl { width: 42%; }
        .modern-notes { width: 58%; padding-inline-end: 24px; }
        .modern-totals { width: 42%; }
        .modern-sections { display: block; }
        .modern-section { display: inline-block; width: 48%; vertical-align: top; }
        .modern-signature { display: block; text-align: end; }
        .modern-signature-line { display: inline-block; }
    @endif
</style>
@endpush

@section('document')
<section class="modern">
    <header class="modern-head">
        <div class="modern-brand">
            @if ($logoSrc)
                <img class="modern-logo" src="{{ $logoSrc }}" alt="{{ $presentation['business']?->name }}">
            @endif
            <h2 class="modern-business">{{ $presentation['business']?->name }}</h2>
            <div class="modern-profile">
                @if ($presentation['profile']['address'])<div>{{ $presentation['profile']['address'] }}</div>@endif
                @if ($presentation['profile']['phone'])<div>{{ $presentation['profile']['phone'] }}</div>@endif
                @if ($presentation['profile']['email'])<div>{{ $presentation['profile']['email'] }}</div>@endif
            </div>
        </div>

        <div class="modern-title">
            <h1>{{ __('documents.invoice') }}</h1>
            <div class="modern-number">{{ $invoice->invoice_number }}</div>
        </div>
    </header>

    @if ($presentation['header_text'])
        <div class="modern-header-note pre-line">{{ $presentation['header_text'] }}</div>
    @endif

    <div class="modern-meta">
        <div>
            <div class="modern-box-label">{{ __('documents.bill_to') }}</div>
            <div class="modern-customer-name">{{ $invoice->customer?->name ?? __('documents.no_customer') }}</div>
            @if ($invoice->customer?->company_name)<div class="muted">{{ $invoice->customer->company_name }}</div>@endif
            @if ($invoice->customer?->address)<div class="muted pre-line">{{ $invoice->customer->address }}</div>@endif
            @if ($invoice->customer?->phone)<div class="muted">{{ $invoice->customer->phone }}</div>@endif
            @if ($invoice->customer?->email)<div class="muted">{{ $invoice->customer->email }}</div>@endif
        </div>

        <dl class="modern-facts">
            <dt>{{ __('documents.issued_on') }}</dt>
            <dd>{{ $invoice->date?->format($dateFormat) }}</dd>
            <dt>{{ __('documents.status') }}</dt>
            <dd>{{ __('invoices.statuses.'.$invoice->status->value) }}</dd>
            @if ($invoice->quotation)
                <dt>{{ __('documents.quotation') }}</dt>
                <dd>{{ $invoice->quotation->quotation_number }}</dd>
            @endif
            <dt>{{ __('documents.created_by') }}</dt>
            <dd>{{ $invoice->createdBy?->name ?? '—' }}</dd>
        </dl>
    </div>

    <table class="modern-table">
        <thead>
            <tr>
                <th>{{ __('documents.description') }}</th>
                <th class="numeric">{{ __('documents.quantity') }}</th>
                <th class="numeric">{{ __('documents.unit_price') }}</th>
                @if ((float) $invoice->tax_amount > 0)<th class="numeric">{{ __('documents.tax') }}</th>@endif
                <th class="numeric">{{ __('documents.amount') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $item)
                <tr>
                    <td>
                        <div>{{ $item->description }}</div>
                        @if ($item->product)<div class="modern-product">{{ $item->product->name }}</div>@endif
                    </td>
                    <td class="numeric money">{{ $item->quantity }}</td>
                    <td class="numeric money">{{ $invoice->currency_code }} {{ number_format((float) $item->unit_price, 2) }}</td>
                    @if ((float) $invoice->tax_amount > 0)
                        <td class="numeric money">{{ $item->tax_rate !== null ? $item->tax_rate.'%' : '—' }}</td>
                    @endif
                    <td class="numeric money">{{ $invoice->currency_code }} {{ number_format((float) $item->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="modern-summary">
        <div class="modern-notes">
            @if ($invoice->notes)
                <div class="modern-box-label">{{ __('invoices.notes') }}</div>
                <div class="pre-line muted">{{ $invoice->notes }}</div>
            @endif
        </div>

        <table class="modern-totals">
            <tr><td>{{ __('documents.subtotal') }}</td><td class="money">{{ $invoice->currency_code }} {{ number_format((float) $invoice->subtotal, 2) }}</td></tr>
            @if ((float) $invoice->discount_amount > 0)
                <tr><td>{{ __('documents.discount') }}</td><td class="money">− {{ $invoice->currency_code }} {{ number_format((float) $invoice->discount_amount, 2) }}</td></tr>
            @endif
            @if ((float) $invoice->tax_amount > 0)
                <tr><td>{{ __('documents.tax') }}</td><td class="money">{{ $invoice->currency_code }} {{ number_format((float) $invoice->tax_amount, 2) }}</td></tr>
            @endif
            <tr class="grand"><td>{{ __('documents.total') }}</td><td class="money">{{ $invoice->currency_code }} {{ number_format((float) $invoice->total, 2) }}</td></tr>
            <tr><td>{{ __('documents.paid') }}</td><td class="money">{{ $invoice->currency_code }} {{ number_format((float) $invoice->amount_paid, 2) }}</td></tr>
            <tr class="due"><td>{{ __('documents.amount_due') }}</td><td class="money">{{ $invoice->currency_code }} {{ number_format((float) $invoice->amount_due, 2) }}</td></tr>
            @if ($invoice->currency_code !== $baseCurrency)
                <tr><td>{{ __('documents.base_amount', ['currency' => $baseCurrency]) }}</td><td class="money">{{ $baseCurrency }} {{ number_format((float) $invoice->base_amount, 2) }}</td></tr>
                <tr><td>{{ __('documents.exchange_rate') }}</td><td class="money">{{ $invoice->exchange_rate }}</td></tr>
            @endif
        </table>
    </div>

    @if ($presentation['terms'] || $presentation['bank_details'])
        <div class="modern-sections">
            @if ($presentation['terms'])
                <section class="modern-section">
                    <h3>{{ __('documents.terms') }}</h3>
                    <div class="pre-line muted">{{ $presentation['terms'] }}</div>
                </section>
            @endif
            @if ($presentation['bank_details'])
                <section class="modern-section">
                    <h3>{{ __('documents.bank_details') }}</h3>
                    <div class="pre-line muted">{{ $presentation['bank_details'] }}</div>
                </section>
            @endif
        </div>
    @endif

    @if ($presentation['signature_line'])
        <div class="modern-signature">
            <div class="modern-signature-line">{{ $presentation['signature_line'] }}</div>
        </div>
    @endif

    @if ($presentation['footer_text'])
        <footer class="modern-footer pre-line">{{ $presentation['footer_text'] }}</footer>
    @endif
</section>
@endsection
