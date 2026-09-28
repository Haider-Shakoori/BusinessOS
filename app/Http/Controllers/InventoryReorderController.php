<?php

namespace App\Http\Controllers;

use App\Enums\ProductType;
use App\Models\InventoryReorderRule;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\InventoryReorderService;
use App\Services\ProductVariantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class InventoryReorderController extends Controller
{
    public function index(Request $request, InventoryReorderService $service): View
    {
        $warehouseId = $request->filled('warehouse_id') && is_numeric($request->input('warehouse_id'))
            ? (int) $request->input('warehouse_id')
            : null;
        $status = $request->filled('status') ? (string) $request->input('status') : null;
        $search = $request->filled('q') ? (string) $request->input('q') : null;

        $baseRows = $service->overview($warehouseId, null, $search);
        $rows = $status !== null && in_array($status, ['ok', 'low', 'out_of_stock', 'replenishing', 'inactive'], true)
            ? $baseRows->where('status', $status)->values()
            : $baseRows;

        return view('inventory.reorder.index', [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get(),
            'products' => Product::query()
                ->where('type', ProductType::Product->value)
                ->with(['variants' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
                ->orderBy('name')
                ->get(),
            'rows' => $rows,
            'summary' => [
                'out_of_stock' => $baseRows->where('status', 'out_of_stock')->count(),
                'low' => $baseRows->where('status', 'low')->count(),
                'replenishing' => $baseRows->where('status', 'replenishing')->count(),
                'ok' => $baseRows->where('status', 'ok')->count(),
                'inactive' => $baseRows->where('status', 'inactive')->count(),
            ],
            'filters' => [
                'warehouse_id' => $warehouseId,
                'status' => $status,
                'q' => $search,
            ],
        ]);
    }

    public function store(
        Request $request,
        BusinessContext $context,
        InventoryReorderService $service,
        ProductVariantService $variants,
    ): RedirectResponse {
        $data = $request->validate([
            'warehouse_id' => [
                'required',
                Rule::exists('warehouses', 'id')->where('business_id', $context->currentId()),
            ],
            'product_id' => [
                'required',
                Rule::exists('products', 'id')->where('business_id', $context->currentId()),
            ],
            'product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where('business_id', $context->currentId()),
            ],
            'reorder_point' => ['required', 'numeric', 'min:0'],
            'target_stock' => ['required', 'numeric', 'gt:reorder_point'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $product = Product::findOrFail($data['product_id']);

        try {
            $variant = $variants->resolve(
                $product,
                isset($data['product_variant_id']) ? (int) $data['product_variant_id'] : null,
            );

            $service->saveRule(
                Warehouse::findOrFail($data['warehouse_id']),
                $product,
                $variant,
                (string) $data['reorder_point'],
                (string) $data['target_stock'],
                $request->boolean('is_active', true),
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['reorder' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', __('operations.reorder.messages.saved'));
    }

    public function createRequisition(
        Request $request,
        BusinessContext $context,
        InventoryReorderService $service,
    ): RedirectResponse {
        $data = $request->validate([
            'rule_ids' => ['required', 'array', 'min:1', 'max:100'],
            'rule_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('inventory_reorder_rules', 'id')->where('business_id', $context->currentId()),
            ],
            'needed_by' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        try {
            $requisition = $service->createRequisition(
                $data['rule_ids'],
                (int) $request->user()->id,
                $data['needed_by'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['reorder' => $exception->getMessage()])->withInput();
        }

        return redirect()
            ->route('purchasing.requisitions.index')
            ->with('status', __('operations.reorder.messages.requisition_created', [
                'number' => $requisition->number,
            ]));
    }

    public function setActive(
        Request $request,
        InventoryReorderRule $inventoryReorderRule,
        InventoryReorderService $service,
    ): RedirectResponse {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $service->setActive($inventoryReorderRule, (bool) $data['is_active']);

        return back()->with('status', __('operations.reorder.messages.status_updated'));
    }

    public function destroy(
        InventoryReorderRule $inventoryReorderRule,
        InventoryReorderService $service,
    ): RedirectResponse {
        $service->delete($inventoryReorderRule);

        return back()->with('status', __('operations.reorder.messages.deleted'));
    }
}
