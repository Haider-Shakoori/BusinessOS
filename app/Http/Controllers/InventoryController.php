<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\InventoryValuationService;
use App\Services\ProductVariantService;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(InventoryValuationService $valuation): View
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

        $balances->each(function (StockMovement $balance) use ($valuation): void {
            $snapshot = $valuation->snapshot(
                (int) $balance->warehouse_id,
                (int) $balance->product_id,
                $balance->product_variant_id !== null ? (int) $balance->product_variant_id : null,
            );

            $balance->setAttribute('average_unit_cost', $snapshot['average_unit_cost']);
            $balance->setAttribute('stock_value', $snapshot['stock_value']);
            $balance->setAttribute('valuation_complete', $snapshot['complete']);
        });

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

    public function storeMovement(
        Request $request,
        BusinessContext $context,
        ProductVariantService $variants,
        InventoryValuationService $valuation,
    ): RedirectResponse
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

        if (Decimal::lt((string) $data['quantity'], '0')) {
            $snapshot = $valuation->snapshot(
                (int) $data['warehouse_id'],
                (int) $data['product_id'],
                $variant?->id,
            );
            $requested = Decimal::sub('0', Decimal::normalize((string) $data['quantity']));

            if (Decimal::lt($snapshot['quantity'], $requested)) {
                return back()->withErrors([
                    'quantity' => __('operations.inventory.insufficient_stock', [
                        'available' => $snapshot['quantity'],
                    ]),
                ])->withInput();
            }

            if (! array_key_exists('unit_cost', $data) || $data['unit_cost'] === null || $data['unit_cost'] === '') {
                $data['unit_cost'] = $snapshot['average_unit_cost'];
            }
        }

        StockMovement::create([
            ...$data,
            'product_variant_id' => $variant?->id,
            'occurred_at' => now(),
        ]);

        return back()->with('status', __('operations.inventory.movement_created'));
    }
}
