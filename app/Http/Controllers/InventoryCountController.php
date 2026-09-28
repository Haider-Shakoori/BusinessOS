<?php

namespace App\Http\Controllers;

use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\InventoryCountService;
use App\Services\ProductVariantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class InventoryCountController extends Controller
{
    public function index(): View
    {
        return view('inventory.counts.index', [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
            'counts' => InventoryCount::query()
                ->with(['warehouse', 'creator'])
                ->withCount('items')
                ->latest('count_date')
                ->latest('id')
                ->limit(100)
                ->get(),
        ]);
    }

    public function store(
        Request $request,
        BusinessContext $context,
        InventoryCountService $service,
    ): RedirectResponse {
        $data = $request->validate([
            'warehouse_id' => [
                'required',
                Rule::exists('warehouses', 'id')->where('business_id', $context->currentId()),
            ],
            'count_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $count = $service->create(
            Warehouse::findOrFail($data['warehouse_id']),
            $data['count_date'],
            $data['notes'] ?? null,
            (int) $request->user()->id,
        );

        return redirect()
            ->route('inventory.counts.show', $count)
            ->with('status', __('operations.stock_counts.messages.created'));
    }

    public function show(InventoryCount $inventoryCount): View
    {
        $inventoryCount->load([
            'warehouse',
            'creator',
            'submitter',
            'approver',
            'items.product',
            'items.variant',
        ]);

        return view('inventory.counts.show', [
            'count' => $inventoryCount,
            'products' => Product::query()
                ->with(['variants' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function addItem(
        Request $request,
        InventoryCount $inventoryCount,
        BusinessContext $context,
        InventoryCountService $service,
        ProductVariantService $variants,
    ): RedirectResponse {
        $data = $request->validate([
            'product_id' => [
                'required',
                Rule::exists('products', 'id')->where('business_id', $context->currentId()),
            ],
            'product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where('business_id', $context->currentId()),
            ],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product = Product::findOrFail($data['product_id']);

        try {
            $variant = $variants->resolve(
                $product,
                isset($data['product_variant_id']) ? (int) $data['product_variant_id'] : null,
            );

            $service->addLine(
                $inventoryCount,
                $product,
                $variant,
                isset($data['unit_cost']) ? (string) $data['unit_cost'] : null,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['stock_count' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', __('operations.stock_counts.messages.item_added'));
    }

    public function updateItem(
        Request $request,
        InventoryCount $inventoryCount,
        InventoryCountItem $inventoryCountItem,
        InventoryCountService $service,
    ): RedirectResponse {
        abort_unless($inventoryCountItem->inventory_count_id === $inventoryCount->id, 404);

        $data = $request->validate([
            'counted_quantity' => ['required', 'numeric', 'min:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $service->record(
                $inventoryCountItem,
                (string) $data['counted_quantity'],
                isset($data['unit_cost']) ? (string) $data['unit_cost'] : null,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['stock_count' => $exception->getMessage()]);
        }

        return back()->with('status', __('operations.stock_counts.messages.count_saved'));
    }

    public function submit(
        Request $request,
        InventoryCount $inventoryCount,
        InventoryCountService $service,
    ): RedirectResponse {
        try {
            $service->submit($inventoryCount, (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['stock_count' => $exception->getMessage()]);
        }

        return back()->with('status', __('operations.stock_counts.messages.submitted'));
    }

    public function post(
        Request $request,
        InventoryCount $inventoryCount,
        InventoryCountService $service,
    ): RedirectResponse {
        try {
            $service->post($inventoryCount, (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['stock_count' => $exception->getMessage()]);
        }

        return back()->with('status', __('operations.stock_counts.messages.posted'));
    }
}
