<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseRequisition;
use App\Services\BusinessContext;
use App\Services\PurchaseRequisitionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchaseRequisitionController extends Controller
{
    public function index(): View
    {
        return view('purchasing.requisitions', [
            'products' => Product::query()
                ->with(['variants' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
                ->orderBy('name')
                ->get(),
            'requisitions' => PurchaseRequisition::query()
                ->with(['requester', 'approver', 'rejector', 'items.product', 'items.variant'])
                ->latest('request_date')
                ->latest('id')
                ->limit(100)
                ->get(),
        ]);
    }

    public function store(Request $request, BusinessContext $context, PurchaseRequisitionService $service): RedirectResponse
    {
        $data = $request->validate([
            'request_date' => ['required', 'date'],
            'needed_by' => ['nullable', 'date', 'after_or_equal:request_date'],
            'purpose' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $context->currentId())],
            'items.*.product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('business_id', $context->currentId())],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.estimated_unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $service->create($data, (int) $request->user()->id);

        return back()->with('status', __('operations.purchasing.requisition_created'));
    }

    public function submit(PurchaseRequisition $purchaseRequisition, PurchaseRequisitionService $service): RedirectResponse
    {
        $service->submit($purchaseRequisition);

        return back()->with('status', __('operations.purchasing.requisition_submitted'));
    }

    public function approve(Request $request, PurchaseRequisition $purchaseRequisition, PurchaseRequisitionService $service): RedirectResponse
    {
        $service->approve($purchaseRequisition, (int) $request->user()->id);

        return back()->with('status', __('operations.purchasing.requisition_approved'));
    }

    public function reject(Request $request, PurchaseRequisition $purchaseRequisition, PurchaseRequisitionService $service): RedirectResponse
    {
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'max:2000'],
        ]);

        $service->reject($purchaseRequisition, (int) $request->user()->id, $data['rejection_reason']);

        return back()->with('status', __('operations.purchasing.requisition_rejected'));
    }
}
