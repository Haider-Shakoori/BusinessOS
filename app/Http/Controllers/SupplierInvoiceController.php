<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Services\BusinessContext;
use App\Services\SupplierInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupplierInvoiceController extends Controller
{
    public function index(SupplierInvoiceService $service): View
    {
        $orders = PurchaseOrder::query()
            ->whereIn('status', ['partially_received', 'received'])
            ->with([
                'supplier',
                'items.product',
                'items.variant',
                'goodsReceipts.items',
                'supplierInvoices.items',
            ])
            ->latest('order_date')
            ->latest('id')
            ->limit(100)
            ->get();

        return view('purchasing.supplier-invoices', [
            'orders' => $orders,
            'availableByOrder' => $orders->mapWithKeys(fn (PurchaseOrder $order): array => [
                $order->id => $service->availableQuantities($order),
            ]),
            'invoices' => SupplierInvoice::query()
                ->with([
                    'purchaseOrder',
                    'supplier',
                    'items.product',
                    'items.variant',
                    'creator',
                    'submitter',
                    'approver',
                    'rejector',
                    'matchOverrider',
                ])
                ->latest('invoice_date')
                ->latest('id')
                ->limit(100)
                ->get(),
        ]);
    }

    public function store(
        Request $request,
        BusinessContext $context,
        SupplierInvoiceService $service,
    ): RedirectResponse {
        $data = $request->validate([
            'purchase_order_id' => [
                'required',
                Rule::exists('purchase_orders', 'id')->where('business_id', $context->currentId()),
            ],
            'supplier_invoice_number' => ['required', 'string', 'max:120'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gte:0', 'decimal:0,4'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'decimal:0,4'],
        ]);

        $order = PurchaseOrder::query()->findOrFail($data['purchase_order_id']);
        $service->create($order, $data, (int) $request->user()->id);

        return redirect()
            ->route('purchasing.supplier-invoices.index')
            ->with('status', __('operations.purchasing.supplier_invoice_created'));
    }

    public function submit(
        Request $request,
        SupplierInvoice $supplierInvoice,
        SupplierInvoiceService $service,
    ): RedirectResponse {
        $service->submit($supplierInvoice, (int) $request->user()->id);

        return back()->with('status', __('operations.purchasing.supplier_invoice_submitted'));
    }

    public function approve(
        Request $request,
        SupplierInvoice $supplierInvoice,
        SupplierInvoiceService $service,
    ): RedirectResponse {
        $data = $request->validate([
            'match_override_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $service->approve(
            $supplierInvoice,
            (int) $request->user()->id,
            $data['match_override_reason'] ?? null,
        );

        return back()->with('status', __('operations.purchasing.supplier_invoice_approved'));
    }

    public function reject(
        Request $request,
        SupplierInvoice $supplierInvoice,
        SupplierInvoiceService $service,
    ): RedirectResponse {
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $service->reject(
            $supplierInvoice,
            (int) $request->user()->id,
            $data['rejection_reason'],
        );

        return back()->with('status', __('operations.purchasing.supplier_invoice_rejected'));
    }
}
