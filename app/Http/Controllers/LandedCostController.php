<?php

namespace App\Http\Controllers;

use App\Models\GoodsReceipt;
use App\Models\LandedCost;
use App\Services\BusinessContext;
use App\Services\LandedCostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LandedCostController extends Controller
{
    public function index(): View
    {
        return view('purchasing.landed-costs', [
            'receipts' => GoodsReceipt::query()
                ->where('status', 'posted')
                ->with([
                    'purchaseOrder.supplier',
                    'warehouse',
                    'items.product',
                    'items.variant',
                    'items.stockMovement',
                    'landedCosts.allocations',
                ])
                ->latest('receipt_date')
                ->latest('id')
                ->limit(100)
                ->get(),
            'landedCosts' => LandedCost::query()
                ->with([
                    'goodsReceipt.purchaseOrder.supplier',
                    'goodsReceipt.warehouse',
                    'charges',
                    'allocations.goodsReceiptItem.product',
                    'allocations.goodsReceiptItem.variant',
                    'creator',
                    'poster',
                    'reverser',
                ])
                ->latest('cost_date')
                ->latest('id')
                ->limit(100)
                ->get(),
        ]);
    }

    public function store(
        Request $request,
        BusinessContext $context,
        LandedCostService $service,
    ): RedirectResponse {
        $data = $request->validate([
            'goods_receipt_id' => [
                'required',
                Rule::exists('goods_receipts', 'id')->where('business_id', $context->currentId()),
            ],
            'cost_date' => ['required', 'date'],
            'allocation_method' => ['required', Rule::in(['value', 'quantity', 'manual'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'charges' => ['required', 'array', 'min:1'],
            'charges.*.category' => [
                'required',
                Rule::in(['freight', 'customs', 'insurance', 'handling', 'transport', 'other']),
            ],
            'charges.*.description' => ['nullable', 'string', 'max:255'],
            'charges.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,4'],
            'manual_allocations' => ['nullable', 'array'],
            'manual_allocations.*.goods_receipt_item_id' => ['required_with:manual_allocations', 'integer'],
            'manual_allocations.*.amount' => ['required_with:manual_allocations', 'numeric', 'gte:0', 'decimal:0,4'],
        ]);

        $receipt = GoodsReceipt::query()->findOrFail($data['goods_receipt_id']);
        $service->create($receipt, $data, (int) $request->user()->id);

        return redirect()
            ->route('purchasing.landed-costs.index')
            ->with('status', __('operations.purchasing.landed_cost_created'));
    }

    public function post(
        Request $request,
        LandedCost $landedCost,
        LandedCostService $service,
    ): RedirectResponse {
        $service->post($landedCost, (int) $request->user()->id);

        return back()->with('status', __('operations.purchasing.landed_cost_posted'));
    }

    public function reverse(
        Request $request,
        LandedCost $landedCost,
        LandedCostService $service,
    ): RedirectResponse {
        $data = $request->validate([
            'reversal_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $service->reverse(
            $landedCost,
            (int) $request->user()->id,
            $data['reversal_reason'] ?? null,
        );

        return back()->with('status', __('operations.purchasing.landed_cost_reversed'));
    }
}
