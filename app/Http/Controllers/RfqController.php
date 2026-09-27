<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequisition;
use App\Models\PurchaseRfq;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Services\BusinessContext;
use App\Services\PurchaseOrderConversionService;
use App\Services\RfqService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RfqController extends Controller
{
    public function index(RfqService $service): View
    {
        $rfqs = PurchaseRfq::query()
            ->with([
                'requisition.items.product',
                'requisition.items.variant',
                'invitedSuppliers',
                'quotations.supplier',
                'quotations.purchaseOrder',
                'quotations.items.product',
                'creator',
            ])
            ->latest('issue_date')
            ->latest('id')
            ->limit(100)
            ->get();

        return view('purchasing.rfqs', [
            'approvedRequisitions' => PurchaseRequisition::query()
                ->where('status', 'approved')
                ->with('items.product')
                ->latest('request_date')
                ->get(),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(),
            'rfqs' => $rfqs,
            'comparisons' => $rfqs->mapWithKeys(fn (PurchaseRfq $rfq): array => [$rfq->id => $service->comparison($rfq)]),
        ]);
    }

    public function store(Request $request, BusinessContext $context, RfqService $service): RedirectResponse
    {
        $data = $request->validate([
            'purchase_requisition_id' => ['required', Rule::exists('purchase_requisitions', 'id')->where('business_id', $context->currentId())],
            'issue_date' => ['required', 'date'],
            'response_due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'supplier_ids' => ['required', 'array', 'min:1'],
            'supplier_ids.*' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('business_id', $context->currentId())->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $requisition = PurchaseRequisition::query()->findOrFail($data['purchase_requisition_id']);
        $service->create($requisition, $data, (int) $request->user()->id);

        return back()->with('status', __('operations.purchasing.rfq_created'));
    }

    public function open(PurchaseRfq $purchaseRfq, RfqService $service): RedirectResponse
    {
        $service->open($purchaseRfq);

        return back()->with('status', __('operations.purchasing.rfq_opened'));
    }

    public function storeQuotation(Request $request, PurchaseRfq $purchaseRfq, BusinessContext $context, RfqService $service): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('business_id', $context->currentId())->whereNull('deleted_at')],
            'supplier_reference' => ['nullable', 'string', 'max:120'],
            'quote_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quote_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_requisition_item_id' => ['required', 'integer'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $supplier = Supplier::query()->findOrFail($data['supplier_id']);
        $service->recordQuotation($purchaseRfq, $supplier, $data, (int) $request->user()->id);

        return back()->with('status', __('operations.purchasing.supplier_quote_recorded'));
    }

    public function award(Request $request, PurchaseRfq $purchaseRfq, SupplierQuotation $supplierQuotation, RfqService $service): RedirectResponse
    {
        $service->award($purchaseRfq, $supplierQuotation, (int) $request->user()->id);

        return back()->with('status', __('operations.purchasing.rfq_awarded'));
    }

    public function convertToPurchaseOrder(
        Request $request,
        PurchaseRfq $purchaseRfq,
        SupplierQuotation $supplierQuotation,
        PurchaseOrderConversionService $service,
    ): RedirectResponse {
        if ($supplierQuotation->purchase_rfq_id !== $purchaseRfq->id) {
            abort(404);
        }

        $data = $request->validate([
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $service->convert($supplierQuotation, $data, (int) $request->user()->id);

        return back()->with('status', __('operations.purchasing.purchase_order_created_from_quote'));
    }
}
