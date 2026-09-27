<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceAdjustment;
use App\Services\BusinessContext;
use App\Services\PaymentService;
use App\Services\SupplierInvoiceService;
use App\Services\SupplierInvoiceSettlementService;
use App\Support\Decimal;
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

        $availableByOrder = $orders->mapWithKeys(fn (PurchaseOrder $order): array => [
            $order->id => $service->availableQuantities($order),
        ]);

        $orders = $orders
            ->filter(fn (PurchaseOrder $order): bool => $availableByOrder
                ->get($order->id, collect())
                ->contains(fn ($quantity): bool => Decimal::gt((string) $quantity, '0')))
            ->values();

        return view('purchasing.supplier-invoices', [
            'orders' => $orders,
            'availableByOrder' => $availableByOrder,
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
                    'paymentAllocations.payment',
                    'adjustments.creator',
                    'adjustments.reverser',
                ])
                ->latest('invoice_date')
                ->latest('id')
                ->limit(100)
                ->get(),
            'paymentMethods' => PaymentMethod::cases(),
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

    public function pay(
        Request $request,
        SupplierInvoice $supplierInvoice,
        PaymentService $payments,
    ): RedirectResponse {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.9999', 'decimal:0,4'],
            'payment_method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'payment_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $payments->recordSupplierInvoice(
            $supplierInvoice,
            $data,
            (int) $request->user()->id,
        );

        return back()->with('status', __('operations.purchasing.supplier_invoice_payment_recorded'));
    }

    public function adjust(
        Request $request,
        SupplierInvoice $supplierInvoice,
        SupplierInvoiceSettlementService $settlements,
    ): RedirectResponse {
        $data = $request->validate([
            'type' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.9999', 'decimal:0,4'],
            'note_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $settlements->createAdjustment(
            $supplierInvoice,
            $data['type'],
            (string) $data['amount'],
            $data['note_date'],
            $data['reason'] ?? null,
            (int) $request->user()->id,
        );

        return back()->with('status', __('operations.purchasing.supplier_invoice_adjustment_posted'));
    }

    public function reverseAdjustment(
        Request $request,
        SupplierInvoice $supplierInvoice,
        SupplierInvoiceAdjustment $supplierInvoiceAdjustment,
        SupplierInvoiceSettlementService $settlements,
    ): RedirectResponse {
        abort_unless(
            (int) $supplierInvoiceAdjustment->supplier_invoice_id === (int) $supplierInvoice->id,
            404,
        );

        $data = $request->validate([
            'reversal_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $settlements->reverseAdjustment(
            $supplierInvoiceAdjustment,
            (int) $request->user()->id,
            $data['reversal_reason'] ?? null,
        );

        return back()->with('status', __('operations.purchasing.supplier_invoice_adjustment_reversed'));
    }
}
