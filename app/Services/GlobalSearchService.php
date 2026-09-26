<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CrmLead;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PosSale;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\Supplier;
use Illuminate\Support\Facades\Gate;

class GlobalSearchService
{
    /**
     * @return list<array{type:string,title:string,subtitle:string,url:string,icon:string}>
     */
    public function search(string $term, int $limitPerType = 8): array
    {
        $term = trim($term);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $results = [];

        if (Gate::allows('customers.view')) {
            foreach (Customer::query()
                ->where(fn ($q) => $q->where('name', 'like', "%{$term}%")
                    ->orWhere('company_name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%"))
                ->limit($limitPerType)
                ->get() as $customer) {
                $results[] = $this->item('customer', $customer->name, $customer->company_name ?: ($customer->phone ?: '—'), route('customers.show', $customer), 'users');
            }
        }

        if (Gate::allows('products.view')) {
            foreach (Product::query()
                ->where(fn ($q) => $q->where('name', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%"))
                ->limit($limitPerType)
                ->get() as $product) {
                $results[] = $this->item('product', $product->name, $product->sku ?: '—', route('products.index', ['search' => $term]), 'cube');
            }
        }

        if (Gate::allows('invoices.view')) {
            foreach (Invoice::query()
                ->with('customer')
                ->where('invoice_number', 'like', "%{$term}%")
                ->limit($limitPerType)
                ->get() as $invoice) {
                $results[] = $this->item('invoice', $invoice->invoice_number, $invoice->customer?->name ?? '—', route('invoices.show', $invoice), 'receipt-percent');
            }
        }

        if (Gate::allows('quotations.view')) {
            foreach (Quotation::query()
                ->with('customer')
                ->where('quotation_number', 'like', "%{$term}%")
                ->limit($limitPerType)
                ->get() as $quotation) {
                $results[] = $this->item('quotation', $quotation->quotation_number, $quotation->customer?->name ?? '—', route('quotations.show', $quotation), 'document-text');
            }
        }

        if (Gate::allows('purchasing.view')) {
            foreach (PurchaseOrder::query()
                ->with('supplier')
                ->where('number', 'like', "%{$term}%")
                ->limit($limitPerType)
                ->get() as $order) {
                $results[] = $this->item('purchase_order', $order->number, $order->supplier?->name ?? '—', route('purchasing.index'), 'shopping-cart');
            }

            foreach (Supplier::query()
                ->where(fn ($q) => $q->where('name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"))
                ->limit($limitPerType)
                ->get() as $supplier) {
                $results[] = $this->item('supplier', $supplier->name, $supplier->phone ?: ($supplier->email ?: '—'), route('purchasing.index'), 'briefcase');
            }
        }

        if (Gate::allows('crm.view')) {
            foreach (CrmLead::query()
                ->where(fn ($q) => $q->where('name', 'like', "%{$term}%")
                    ->orWhere('company', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"))
                ->limit($limitPerType)
                ->get() as $lead) {
                $results[] = $this->item('crm_lead', $lead->name, $lead->company ?: $lead->status, route('crm.index'), 'contact-card');
            }
        }

        if (Gate::allows('manufacturing.view')) {
            foreach (ProductionOrder::query()
                ->with('product')
                ->where('number', 'like', "%{$term}%")
                ->limit($limitPerType)
                ->get() as $order) {
                $results[] = $this->item('production_order', $order->number, $order->product?->name ?? '—', route('manufacturing.index'), 'factory');
            }
        }

        if (Gate::allows('pos.view')) {
            foreach (PosSale::query()
                ->where('sale_number', 'like', "%{$term}%")
                ->limit($limitPerType)
                ->get() as $sale) {
                $results[] = $this->item('pos_sale', $sale->sale_number, number_format((float) $sale->total, 2).' '.$sale->currency_code, route('pos.receipt', $sale), 'building-storefront');
            }
        }

        if (Gate::allows('accounting.view')) {
            foreach (Account::query()
                ->where(fn ($q) => $q->where('code', 'like', "%{$term}%")
                    ->orWhere('name', 'like', "%{$term}%"))
                ->limit($limitPerType)
                ->get() as $account) {
                $results[] = $this->item('account', $account->code.' — '.$account->name, ucfirst($account->type), route('accounting.index'), 'ledger');
            }
        }

        return array_slice($results, 0, 60);
    }

    /**
     * @return array{type:string,title:string,subtitle:string,url:string,icon:string}
     */
    private function item(string $type, string $title, string $subtitle, string $url, string $icon): array
    {
        return compact('type', 'title', 'subtitle', 'url', 'icon');
    }
}
