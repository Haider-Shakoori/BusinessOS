<?php

namespace App\Http\Controllers;

use App\Models\CrmLead;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\ModuleManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class GlobalSearchController extends Controller
{
    public function index(Request $request, ModuleManager $modules): View
    {
        $query = trim((string) $request->string('q'));
        $results = collect();

        if (mb_strlen($query) >= 2) {
            if ($modules->isEnabled('customers') && Gate::allows('customers.view')) {
                Customer::query()
                    ->where(function ($builder) use ($query): void {
                        $builder->where('name', 'like', "%{$query}%")
                            ->orWhere('company_name', 'like', "%{$query}%")
                            ->orWhere('email', 'like', "%{$query}%")
                            ->orWhere('phone', 'like', "%{$query}%");
                    })
                    ->limit(8)
                    ->get()
                    ->each(fn ($item) => $results->push([
                        'type' => __('navigation.customers'),
                        'title' => $item->name,
                        'subtitle' => $item->company_name ?: $item->email,
                        'url' => route('customers.show', $item),
                        'icon' => 'users',
                    ]));
            }

            if ($modules->isEnabled('products') && Gate::allows('products.view')) {
                Product::query()
                    ->where(function ($builder) use ($query): void {
                        $builder->where('name', 'like', "%{$query}%")
                            ->orWhere('sku', 'like', "%{$query}%");
                    })
                    ->limit(8)
                    ->get()
                    ->each(fn ($item) => $results->push([
                        'type' => __('navigation.products'),
                        'title' => $item->name,
                        'subtitle' => $item->sku,
                        'url' => route('products.show', $item),
                        'icon' => 'gift',
                    ]));
            }

            if ($modules->isEnabled('sales') && Gate::allows('invoices.view')) {
                Invoice::query()
                    ->with('customer')
                    ->where('invoice_number', 'like', "%{$query}%")
                    ->limit(8)
                    ->get()
                    ->each(fn ($item) => $results->push([
                        'type' => __('navigation.invoices'),
                        'title' => $item->invoice_number,
                        'subtitle' => $item->customer?->name,
                        'url' => route('invoices.show', $item),
                        'icon' => 'clipboard-document-list',
                    ]));
            }

            if ($modules->isEnabled('purchasing') && Gate::allows('purchasing.view')) {
                Supplier::query()
                    ->where(function ($builder) use ($query): void {
                        $builder->where('name', 'like', "%{$query}%")
                            ->orWhere('email', 'like', "%{$query}%")
                            ->orWhere('phone', 'like', "%{$query}%");
                    })
                    ->limit(6)
                    ->get()
                    ->each(fn ($item) => $results->push([
                        'type' => __('system.search.supplier'),
                        'title' => $item->name,
                        'subtitle' => $item->phone ?: $item->email,
                        'url' => route('purchasing.index'),
                        'icon' => 'shopping-cart',
                    ]));

                PurchaseOrder::query()
                    ->with('supplier')
                    ->where('number', 'like', "%{$query}%")
                    ->limit(6)
                    ->get()
                    ->each(fn ($item) => $results->push([
                        'type' => __('system.search.purchase_order'),
                        'title' => $item->number,
                        'subtitle' => $item->supplier?->name,
                        'url' => route('purchasing.index'),
                        'icon' => 'shopping-cart',
                    ]));
            }

            if ($modules->isEnabled('crm') && Gate::allows('crm.view')) {
                CrmLead::query()
                    ->where(function ($builder) use ($query): void {
                        $builder->where('name', 'like', "%{$query}%")
                            ->orWhere('company', 'like', "%{$query}%")
                            ->orWhere('email', 'like', "%{$query}%")
                            ->orWhere('phone', 'like', "%{$query}%");
                    })
                    ->limit(8)
                    ->get()
                    ->each(fn ($item) => $results->push([
                        'type' => __('navigation.crm'),
                        'title' => $item->name,
                        'subtitle' => $item->company,
                        'url' => route('crm.index'),
                        'icon' => 'contact-card',
                    ]));
            }

            if ($modules->isEnabled('manufacturing') && Gate::allows('manufacturing.view')) {
                ProductionOrder::query()
                    ->with('product')
                    ->where('number', 'like', "%{$query}%")
                    ->limit(8)
                    ->get()
                    ->each(fn ($item) => $results->push([
                        'type' => __('system.search.production_order'),
                        'title' => $item->number,
                        'subtitle' => $item->product?->name,
                        'url' => route('manufacturing.index'),
                        'icon' => 'factory',
                    ]));
            }
        }

        return view('system.global-search', [
            'query' => $query,
            'results' => $results->take(50),
        ]);
    }
}
