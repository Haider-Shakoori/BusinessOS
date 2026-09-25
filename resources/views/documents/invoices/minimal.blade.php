@extends('documents.layout')

@push('document-styles')
<style>
    .minimal { padding: 52px 54px; }
    .minimal-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 36px; border-bottom: 2px solid var(--ink); padding-bottom: 24px; }
    .minimal-brand { display: flex; align-items: flex-start; gap: 16px; min-width: 0; }
    .minimal-logo { width: 74px; max-height: 62px; object-fit: contain; }
    .minimal-business { margin: 0; font-size: 20px; line-height: 1.2; }
    .minimal-contact { margin-top: 6px; color: var(--muted); font-size: 11px; }
    .minimal-title { text-align: end; }
    .minimal-title h1 { margin: 0; font-size: 28px; font-weight: 500; letter-spacing: .04em; text-transform: uppercase; }
    .minimal-number { margin-top: 5px; color: var(--muted); font-weight: 650; }
    .minimal-header-note { margin-top: 18px; color: var(--muted); font-style: italic; }
    .minimal-info { display: grid; grid-template-columns: 1fr 1fr; gap: 42px; margin-top: 34px; }
    .minimal-label { margin-bottom: 8px; color: var(--muted); font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
    .minimal-customer { font-size: 15px; font-weight: 700; }
    .minimal-facts { width: 100%; border-collapse: collapse; }
    .minimal-facts td { padding: 3px 0; }
    .minimal-facts td:first-child { color: var(--muted); }
    .minimal-facts td:last-child { text-align: end; font-weight: 600; }
    .minimal-table { width: 100%; margin-top: 36px; border-collapse: collapse; }
    .minimal-table th { border-bottom: 1px solid var(--ink); padding: 8px 5px; color: var(--muted); font-size: 10px; font-weight: 700; letter-spacing: .06em; text-align: start; text-transform: uppercase; }
    .minimal-table td { border-bottom: 1px solid var(--line); padding: 11px 5px; vertical-align: top; }
    .minimal-table .numeric { text-align: end; }
    .minimal-product { color: var(--muted); font-size: 10px; margin-top: 2px; }
    .minimal-after { display: grid; grid-template-columns: 1fr minmax(250px, 36%); gap: 48px; margin-top: 28px; }
    .minimal-totals { width: 100%; border-collapse: collapse; }
    .minimal-totals td { padding: 4px 0; }
    .minimal-totals td:last-child { text-align: end; font-weight: 600; }
    .minimal-totals .grand td { border-top: 1px solid var(--ink); padding-top: 10px; font-size: 16px; font-weight: 800; }
    .minimal-totals .due td { color: var(--accent); padding-top: 8px; font-weight: 800; }
    .minimal-section { margin-top: 28px; max-width: 72%; }
    .minimal-section h3 { margin: 0 0 5px; font-size: 10px; letter-spacing: .07em; text-transform: uppercase; }
    .minimal-signature { margin-top: 46px; width: 220px; margin-inline-start: auto; border-top: 1px solid #94a3b8; padding-top: 7px; color: var(--muted); text-align: center; }
    .minimal-footer { margin-top: 44px; padding-top: 10px; border-top: 1px solid var(--line); color: var(--muted); font-size: 10px; text-align: center; }

    @media (max-width: 700px) {
        .minimal { padding: 30px 22px; }
        .minimal-head, .minimal-info, .minimal-after { display: grid; grid-template-columns: 1fr; }
        .minimal-title { text-align: start; }
        .minimal-section { max-width: 100%; }
    }
</style>
@endpush

@section('document')
<section class="minimal">
    <header class="minimal-head">
        <div class="minimal-brand">
            @if ($presentation['logo_url'])
                <img class="minimal-logo" src="{{ $presentation['logo_url'] }}" alt="{{ $presentation['business']?->name }}">
            @endif
            <div>
                <h2 class="minimal-business">{{ $presentation['business']?->name }}</h2>
                <div class="minimal-contact">
                    @if ($presentation['profile']['address'])<div>{{ $presentation['profile']['address'] }}</div>@endif
                    @if ($presentation['profile']['phone'])<div>{{ $presentation['profile']['phone'] }}</div>@endif
                    @if ($presentation['profile']['email'])<div>{{ $presentation['profile']['email'] }}</div>@endif
                </div>
            </div>
        </div>

        <div class="minimal-title">
            <h1>{{ __('documents.invoice') }}</h1>
            <div class="minimal-number">{{ $invoice->invoice_number }}</div>
        </div>
    </header>

    @if ($presentation['header_text'])
        <div class="minimal-header-note pre-line">{{ $presentation['header_text'] }}</div>
    @endif

    <div class="minimal-info">
        <div>
            <div class="minimal-label">{{ __('documents.bill_to') }}</div>
            <div class="minimal-customer">{{ $invoice->customer?->name ?? __('documents.no_customer') }}</div>
            @if ($invoice->customer?->company_name)<div class="muted">{{ $invoice->customer->company_name }}</div>@endif
            @if ($invoice->customer?->address)<div class="muted pre-line">{{ $invoice->customer->address }}</div>@endif
            @if ($invoice->customer?->phone)<div class="muted">{{ $invoice->customer->phone }}</div>@endif
            @if ($invoice->customer?->email)<div class="muted">{{ $invoice->customer->email }}</div>@endif
        </div>

        <table class="minimal-facts">
            <tr><td>{{ __('documents.issued_on') }}</td><td>{{ $invoice->date?->format($dateFormat) }}</td></tr>
            <tr><td>{{ __('documents.status') }}</td><td>{{ __('invoices.statuses.'.$invoice->status->value) }}</td></tr>
            @if ($invoice->quotation)<tr><td>{{ __('documents.quotation') }}</td><td>{{ $invoice->quotation->quotation_number }}</td></tr>@endif
            <tr><td>{{ __('documents.created_by') }}</td><td>{{ $invoice->createdBy?->name ?? '—' }}</td></tr>
        </table>
    </div>

    <table class="minimal-table">
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
                        @if ($item->product)<div class="minimal-product">{{ $item->product->name }}</div>@endif
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

    <div class="minimal-after">
        <div>
            @if ($invoice->notes)
                <div class="minimal-label">{{ __('invoices.notes') }}</div>
                <div class="pre-line muted">{{ $invoice->notes }}</div>
            @endif
        </div>
        <table class="minimal-totals">
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
        </table>
    </div>

    @if ($presentation['terms'])
        <section class="minimal-section">
            <h3>{{ __('documents.terms') }}</h3>
            <div class="pre-line muted">{{ $presentation['terms'] }}</div>
        </section>
    @endif

    @if ($presentation['bank_details'])
        <section class="minimal-section">
            <h3>{{ __('documents.bank_details') }}</h3>
            <div class="pre-line muted">{{ $presentation['bank_details'] }}</div>
        </section>
    @endif

    @if ($presentation['signature_line'])
        <div class="minimal-signature">{{ $presentation['signature_line'] }}</div>
    @endif

    @if ($presentation['footer_text'])
        <footer class="minimal-footer pre-line">{{ $presentation['footer_text'] }}</footer>
    @endif
</section>
@endsection
