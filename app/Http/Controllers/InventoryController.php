<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\ProductVariantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(): View
    {
        $warehouses = Warehouse::query()->orderBy('name')->get();
        $products = Product::query()->with(['variants' => fn ($query) => $query->where('is_active', true)->orderBy('name')])->orderBy('name')->get();
        $movements = StockMovement::query()
            ->with(['warehouse', 'product', 'variant'])
            ->latest('occurred_at')
            ->limit(50)
            ->get();

        $balances = StockMovement::query()
            ->selectRaw('product_id, product_variant_id, warehouse_id, SUM(quantity) as quantity')
            ->groupBy('product_id', 'product_variant_id', 'warehouse_id')
            ->with(['warehouse', 'product', 'variant'])
            ->get();

        return view('inventory.index', compact('warehouses', 'products', 'movements', 'balances'));
    }

    public function storeWarehouse(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('warehouses', 'code')->where('business_id', $context->currentId())],
            'name' => ['required', 'string', 'max:255'],
        ]);

        Warehouse::create($data + ['is_active' => true]);

        return back()->with('status', __('operations.inventory.warehouse_created'));
    }

    public function storeMovement(Request $request, BusinessContext $context, ProductVariantService $variants): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('business_id', $context->currentId())],
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $context->currentId())],
            'product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('business_id', $context->currentId())],
            'type' => ['required', Rule::in(['opening', 'purchase', 'sale', 'adjustment', 'production_in', 'production_out'])],
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $product = Product::findOrFail($data['product_id']);

        try {
            $variant = $variants->resolve(
                $product,
                isset($data['product_variant_id']) ? (int) $data['product_variant_id'] : null,
            );
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['product_variant_id' => $exception->getMessage()])->withInput();
        }

        StockMovement::create([
            ...$data,
            'product_variant_id' => $variant?->id,
            'occurred_at' => now(),
        ]);

        return back()->with('status', __('operations.inventory.movement_created'));
    }
}
