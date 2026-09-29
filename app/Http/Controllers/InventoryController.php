<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\BusinessContext;
use App\Services\BusinessSettings;
use App\Services\InventoryAvailabilityService;
use App\Services\InventoryValuationService;
use App\Services\ProductVariantService;
use App\Services\WarehouseLocationService;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryController extends Controller
{
    public function index(InventoryValuationService $valuation, InventoryAvailabilityService $availability): View
    {
        $warehouses = Warehouse::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('name')->get();
        $products = Product::query()->with(['variants' => fn ($query) => $query->where('is_active', true)->orderBy('name')])->orderBy('name')->get();
        $locations = WarehouseLocation::query()
            ->where('is_active', true)
            ->with('warehouse')
            ->orderBy('warehouse_id')
            ->orderByDesc('is_default')
            ->orderBy('code')
            ->get();
        $movements = StockMovement::query()
            ->with(['warehouse', 'location', 'product', 'variant'])
            ->latest('occurred_at')
            ->limit(50)
            ->get();

        $balances = StockMovement::query()
            ->selectRaw('product_id, product_variant_id, warehouse_id, SUM(quantity) as quantity')
            ->groupBy('product_id', 'product_variant_id', 'warehouse_id')
            ->with(['warehouse', 'product', 'variant'])
            ->get();

        $balances->each(function (StockMovement $balance) use ($valuation, $availability): void {
            $snapshot = $valuation->snapshot(
                (int) $balance->warehouse_id,
                (int) $balance->product_id,
                $balance->product_variant_id !== null ? (int) $balance->product_variant_id : null,
            );

            $reserved = $availability->reservedQuantity(
                (int) $balance->warehouse_id,
                (int) $balance->product_id,
                $balance->product_variant_id !== null ? (int) $balance->product_variant_id : null,
            );
            $available = Decimal::min(Decimal::sub($snapshot['quantity'], $reserved), '0');

            $balance->setAttribute('average_unit_cost', $snapshot['average_unit_cost']);
            $balance->setAttribute('stock_value', $snapshot['stock_value']);
            $balance->setAttribute('valuation_complete', $snapshot['complete']);
            $balance->setAttribute('reserved_quantity', $reserved);
            $balance->setAttribute('available_quantity', $available);
        });

        return view('inventory.index', compact('warehouses', 'locations', 'products', 'movements', 'balances'));
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
        BusinessSettings $settings,
        ProductVariantService $variants,
        InventoryValuationService $valuation,
        InventoryAvailabilityService $availability,
        WarehouseLocationService $locations,
    ): RedirectResponse {
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('business_id', $context->currentId())->where('is_active', true)],
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')
                    ->where('business_id', $context->currentId())
                    ->where('warehouse_id', $request->integer('warehouse_id'))
                    ->where('is_active', true),
            ],
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $context->currentId())],
            'product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('business_id', $context->currentId())],
            'type' => ['required', Rule::in(['opening', 'purchase', 'sale', 'adjustment', 'production_in', 'production_out'])],
            'quantity' => ['required', 'numeric', 'not_in:0'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $product = Product::findOrFail($data['product_id']);

        try {
            $location = $locations->forMovement(
                (int) $data['warehouse_id'],
                isset($data['location_id']) ? (int) $data['location_id'] : null,
            );
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['location_id' => $exception->getMessage()])->withInput();
        }

        try {
            $variant = $variants->resolve(
                $product,
                isset($data['product_variant_id']) ? (int) $data['product_variant_id'] : null,
            );
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['product_variant_id' => $exception->getMessage()])->withInput();
        }

        try {
            DB::transaction(function () use (&$data, $variant, $location, $settings, $availability, $valuation): void {
                if (Decimal::lt((string) $data['quantity'], '0')) {
                    Product::query()->lockForUpdate()->findOrFail($data['product_id']);

                    $snapshot = $availability->snapshot(
                        (int) $data['warehouse_id'],
                        (int) $data['product_id'],
                        $variant?->id,
                        $location->id,
                    );
                    $requested = Decimal::sub('0', Decimal::normalize((string) $data['quantity']));

                    if (! (bool) $settings->get('inventory.allow_negative_stock', false)
                        && Decimal::lt($snapshot['available'], $requested)) {
                        throw new \RuntimeException(__('operations.inventory.insufficient_stock', [
                            'available' => $snapshot['available'],
                        ]));
                    }

                    if (! array_key_exists('unit_cost', $data) || $data['unit_cost'] === null || $data['unit_cost'] === '') {
                        $issueCost = $valuation->issueUnitCost(
                            (int) $data['warehouse_id'],
                            (int) $data['product_id'],
                            $variant?->id,
                            $location->id,
                        );
                        $data['unit_cost'] = Decimal::gt($issueCost, '0') ? $issueCost : null;
                    }
                }

                StockMovement::create([
                    ...$data,
                    'location_id' => $location->id,
                    'product_variant_id' => $variant?->id,
                    'occurred_at' => now(),
                ]);
            });
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['quantity' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', __('operations.inventory.movement_created'));
    }
}
