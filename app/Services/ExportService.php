<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\ProductType;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Support\LazyCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportService
{
    public function customers(array $filters): StreamedResponse
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        $rows = Customer::query()
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('company_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('created_at', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('created_at', '<=', $dateTo))
            ->orderBy('name')
            ->orderBy('id')
            ->lazy(500);

        return $this->download(
            'customers',
            ['Name', 'Company', 'Email', 'Phone', 'Address', 'Opening Balance', 'Opening Balance Date', 'Notes'],
            $rows->map(fn (Customer $customer) => [
                $this->safeText($customer->name),
                $this->safeText($customer->company_name),
                $this->safeText($customer->email),
                $this->safeText($customer->phone),
                $this->safeText($customer->address),
                (string) $customer->opening_balance,
                $customer->opening_balance_date?->format('Y-m-d') ?? '',
                $this->safeText($customer->notes),
            ]),
        );
    }

    public function products(array $filters): StreamedResponse
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $type = ProductType::tryFrom((string) ($filters['type'] ?? ''));

        $rows = Product::query()
            ->with(['category', 'unit', 'tax'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when($type !== null, fn ($query) => $query->where('type', $type->value))
            ->orderBy('name')
            ->orderBy('id')
            ->lazy(500);

        return $this->download(
            'products',
            ['Type', 'Name', 'SKU', 'Category', 'Unit', 'Tax', 'Sale Price', 'Description'],
            $rows->map(fn (Product $product) => [
                $product->type->value,
                $this->safeText($product->name),
                $this->safeText($product->sku),
                $this->safeText($product->category?->name),
                $this->safeText($product->unit?->name),
                $this->safeText($product->tax?->name),
                (string) $product->sale_price,
                $this->safeText($product->description),
            ]),
        );
    }

    public function invoices(array $filters): StreamedResponse
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $status = InvoiceStatus::tryFrom((string) ($filters['status'] ?? ''));

        $rows = Invoice::query()
            ->with(['customer', 'quotation'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('invoice_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($query) use ($search) {
                            $query->where('name', 'like', "%{$search}%")
                                ->orWhere('company_name', 'like', "%{$search}%");
                        });
                });
            })
            ->when($status !== null, fn ($query) => $query->where('status', $status->value))
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->lazy(500);

        return $this->download(
            'invoices',
            [
                'Invoice Number', 'Date', 'Customer', 'Status', 'Currency',
                'Exchange Rate', 'Subtotal', 'Discount Type', 'Discount Amount',
                'Tax Amount', 'Total', 'Amount Paid', 'Amount Due', 'Base Amount',
                'Quotation Number', 'Notes',
            ],
            $rows->map(fn (Invoice $invoice) => [
                $invoice->invoice_number,
                $invoice->date?->format('Y-m-d') ?? '',
                $this->safeText($invoice->customer?->name),
                $invoice->status->value,
                $invoice->currency_code ?? '',
                (string) ($invoice->exchange_rate ?? ''),
                (string) $invoice->subtotal,
                $invoice->discount_type ?? '',
                (string) $invoice->discount_amount,
                (string) $invoice->tax_amount,
                (string) $invoice->total,
                (string) $invoice->amount_paid,
                (string) $invoice->amount_due,
                (string) ($invoice->base_amount ?? ''),
                $invoice->quotation?->quotation_number ?? '',
                $this->safeText($invoice->notes),
            ]),
        );
    }

    public function expenses(array $filters, string $name = 'expenses'): StreamedResponse
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $categoryId = $filters['category_id'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $rows = Expense::query()
            ->with(['category', 'createdBy'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($query) use ($search) {
                    $query->where('expense_number', 'like', "%{$search}%")
                        ->orWhere('reference', 'like', "%{$search}%")
                        ->orWhere('vendor', 'like', "%{$search}%");
                });
            })
            ->when(! blank($categoryId), fn ($query) => $query->where('category_id', (int) $categoryId))
            ->when(! blank($dateFrom), fn ($query) => $query->whereDate('expense_date', '>=', $dateFrom))
            ->when(! blank($dateTo), fn ($query) => $query->whereDate('expense_date', '<=', $dateTo))
            ->orderBy('expense_date', 'desc')
            ->orderBy('id', 'desc')
            ->lazy(500);

        return $this->download(
            $name,
            [
                'Expense Number', 'Date', 'Category', 'Vendor', 'Reference',
                'Payment Method', 'Currency', 'Exchange Rate', 'Amount',
                'Base Amount', 'Created By', 'Notes',
            ],
            $rows->map(fn (Expense $expense) => [
                $expense->expense_number,
                $expense->expense_date?->format('Y-m-d') ?? '',
                $this->safeText($expense->category?->name),
                $this->safeText($expense->vendor),
                $this->safeText($expense->reference),
                $expense->payment_method?->value ?? '',
                $expense->currency_code ?? '',
                (string) ($expense->exchange_rate ?? ''),
                (string) $expense->amount,
                (string) ($expense->base_amount ?? ''),
                $this->safeText($expense->createdBy?->name),
                $this->safeText($expense->notes),
            ]),
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  LazyCollection<int, array<int, string>>  $rows
     */
    private function download(string $name, array $headers, LazyCollection $rows): StreamedResponse
    {
        $filename = $name.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeText(?string $value): string
    {
        $value ??= '';
        $trimmed = ltrim($value);

        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true)) {
            return "'".$value;
        }

        if ($trimmed !== '' && in_array($trimmed[0], ["\t", "\r", "\n"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
