<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Support\Decimal;

final class DocumentService
{
    public function __construct(
        private readonly DocumentThemeService $themes,
        private readonly BusinessSettings $settings,
        private readonly CurrencyService $currencies,
        private readonly CustomerLedgerService $ledger,
        private readonly SupplierLedgerService $supplierLedger,
    ) {
        //
    }

    /**
     * @return array{view: string, filename: string, data: array<string, mixed>}
     */
    public function invoice(Invoice $invoice, bool $forPdf = false): array
    {
        $invoice->load(['customer', 'items.product', 'items.tax', 'createdBy', 'quotation']);

        $theme = $this->themes->resolve('invoice');

        return [
            'view' => $theme['view'],
            'filename' => 'invoice-'.$invoice->invoice_number.'.pdf',
            'data' => [
                'invoice' => $invoice,
                'theme' => $theme,
                'presentation' => $this->themes->presentation($forPdf),
                'dateFormat' => (string) $this->settings->get('regional.date_format', 'Y-m-d'),
                'baseCurrency' => $this->currencies->baseCurrency(),
                'pdfMode' => $forPdf,
                'documentTitle' => __('documents.invoice').' '.$invoice->invoice_number,
                'backUrl' => route('invoices.show', $invoice),
                'pdfUrl' => route('invoices.pdf', $invoice),
                'downloadUrl' => route('invoices.pdf', ['invoice' => $invoice, 'download' => 1]),
            ],
        ];
    }

    /**
     * @return array{view: string, filename: string, data: array<string, mixed>}
     */
    public function quotation(Quotation $quotation, bool $forPdf = false): array
    {
        $quotation->load(['customer', 'items.product', 'items.tax', 'createdBy']);

        $theme = $this->themes->resolve('quotation');

        return [
            'view' => $theme['view'],
            'filename' => 'quotation-'.$quotation->quotation_number.'.pdf',
            'data' => [
                'quotation' => $quotation,
                'theme' => $theme,
                'presentation' => $this->themes->presentation($forPdf),
                'dateFormat' => (string) $this->settings->get('regional.date_format', 'Y-m-d'),
                'baseCurrency' => $this->currencies->baseCurrency(),
                'pdfMode' => $forPdf,
                'documentTitle' => __('documents.quotation').' '.$quotation->quotation_number,
                'backUrl' => route('quotations.show', $quotation),
                'pdfUrl' => route('quotations.pdf', $quotation),
                'downloadUrl' => route('quotations.pdf', ['quotation' => $quotation, 'download' => 1]),
            ],
        ];
    }

    /**
     * @param  array{date_from?: string|null, date_to?: string|null}  $filters
     * @return array{view: string, filename: string, data: array<string, mixed>}
     */
    public function supplierStatement(Supplier $supplier, array $filters = [], bool $forPdf = false): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $result = $this->supplierLedger->ledger($supplier, [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        $periodDebits = '0.0000';
        $periodCredits = '0.0000';

        foreach ($result['rows'] as $row) {
            if (($row['type'] ?? '') === 'brought_forward') {
                continue;
            }

            $periodDebits = Decimal::add($periodDebits, (string) ($row['debit'] ?? '0'));
            $periodCredits = Decimal::add($periodCredits, (string) ($row['credit'] ?? '0'));
        }

        $routeFilters = array_filter([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ], fn ($value): bool => is_string($value) && $value !== '');

        return [
            'view' => 'documents.supplier-statement',
            'filename' => 'supplier-statement-'.$supplier->id.'.pdf',
            'data' => [
                'supplier' => $supplier,
                'presentation' => $this->themes->presentation($forPdf),
                'dateFormat' => (string) $this->settings->get('regional.date_format', 'Y-m-d'),
                'baseCurrency' => $this->currencies->baseCurrency(),
                'rows' => $result['rows'],
                'closingBalance' => $result['closing_balance'],
                'summary' => $this->supplierLedger->summary($supplier),
                'periodDebits' => $periodDebits,
                'periodCredits' => $periodCredits,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'pdfMode' => $forPdf,
                'documentTitle' => __('suppliers.statement.title').' — '.$supplier->name,
                'backUrl' => route('suppliers.ledger', ['supplier' => $supplier] + $routeFilters),
                'pdfUrl' => route('suppliers.statement.pdf', ['supplier' => $supplier] + $routeFilters),
                'downloadUrl' => route('suppliers.statement.pdf', ['supplier' => $supplier, 'download' => 1] + $routeFilters),
            ],
        ];
    }

    /**
     * @param  array{date_from?: string|null, date_to?: string|null}  $filters
     * @return array{view: string, filename: string, data: array<string, mixed>}
     */
    public function statement(Customer $customer, array $filters = [], bool $forPdf = false): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $result = $this->ledger->ledger($customer, [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        $periodDebits = '0.0000';
        $periodCredits = '0.0000';

        foreach ($result['rows'] as $row) {
            if (($row['type'] ?? '') === 'brought_forward') {
                continue;
            }

            $periodDebits = Decimal::add($periodDebits, $row['base_debit'] ?? $row['debit']);
            $periodCredits = Decimal::add($periodCredits, $row['base_credit'] ?? $row['credit']);
        }

        $theme = $this->themes->resolve('statement');
        $routeFilters = array_filter([
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ], fn ($value): bool => is_string($value) && $value !== '');

        return [
            'view' => $theme['view'],
            'filename' => 'statement-'.$customer->id.'.pdf',
            'data' => [
                'customer' => $customer,
                'theme' => $theme,
                'presentation' => $this->themes->presentation($forPdf),
                'dateFormat' => (string) $this->settings->get('regional.date_format', 'Y-m-d'),
                'baseCurrency' => $this->currencies->baseCurrency(),
                'rows' => $result['rows'],
                'broughtForward' => $result['brought_forward'],
                'openingBalance' => $this->ledger->openingBalance($customer),
                'closingBalance' => $result['closing_balance'],
                'periodDebits' => $periodDebits,
                'periodCredits' => $periodCredits,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'pdfMode' => $forPdf,
                'documentTitle' => __('documents.statement').' — '.$customer->name,
                'backUrl' => route('customers.ledger', ['customer' => $customer] + $routeFilters),
                'pdfUrl' => route('customers.statement.pdf', ['customer' => $customer] + $routeFilters),
                'downloadUrl' => route('customers.statement.pdf', ['customer' => $customer, 'download' => 1] + $routeFilters),
            ],
        ];
    }
}
