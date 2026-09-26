<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(): View
    {
        $warehouses = Warehouse::query()->orderBy('name')->get();
        $products = Product::query()->orderBy('name')->get();
        $movements = StockMovement::query()
            ->with(['warehouse', 'product'])
            ->latest('occurred_at')
            ->limit(50)
            ->get();

        $balances = StockMovement::query()
            ->selectRaw('product_id, warehouse_id, SUM(quantity) as quantity')
            ->groupBy('product_id', 'warehouse_id')
            ->with(['warehouse', 'product'])
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

    public function storeMovement(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')],
            'product_id' => ['required', Rule::exists('products', 'id')],
            'type' => ['required', Rule::in(['opening', 'purchase', 'sale', 'adjustment', 'production_in', 'production_out'])],
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        StockMovement::create($data + ['occurred_at' => now()]);

        return back()->with('status', __('operations.inventory.movement_created'));
    }
}
