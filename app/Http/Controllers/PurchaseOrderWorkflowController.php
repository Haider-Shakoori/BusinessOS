<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\SupplierQuotation;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\PurchaseOrderWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseOrderWorkflowController extends Controller
{
    public function convertQuotation(
        Request $request,
        SupplierQuotation $supplierQuotation,
        PurchaseOrderWorkflowService $workflow,
    ): RedirectResponse {
        $data = $request->validate([
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $workflow->convertSelectedQuotation($supplierQuotation, $data, (int) $request->user()->id);

        return redirect()->route('purchasing.index')
            ->with('status', __('operations.purchasing.quote_converted_to_po'));
    }

    public function storeReceipt(
        Request $request,
        PurchaseOrder $purchaseOrder,
        BusinessContext $context,
        PurchaseOrderWorkflowService $workflow,
    ): RedirectResponse {
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('business_id', $context->currentId())],
            'receipt_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);

        $workflow->receive(
            $purchaseOrder,
            $warehouse,
            $data['receipt_date'],
            $data['items'],
            (int) $request->user()->id,
            $data['notes'] ?? null,
        );

        return back()->with('status', __('operations.purchasing.goods_receipt_posted'));
    }
}
