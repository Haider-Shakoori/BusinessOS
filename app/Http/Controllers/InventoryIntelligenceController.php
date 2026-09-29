<?php

namespace App\Http\Controllers;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\CurrencyService;
use App\Services\InventoryIntelligenceService;
use App\Services\InventoryLowStockAlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryIntelligenceController extends Controller
{
    public function index(
        Request $request,
        BusinessContext $context,
        CurrencyService $currencies,
        InventoryIntelligenceService $intelligence,
    ): View {
        $filters = $request->validate([
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where('business_id', $context->currentId()),
            ],
            'product_id' => [
                'nullable',
                'integer',
                Rule::exists('products', 'id')->where('business_id', $context->currentId()),
            ],
            'slow_days' => ['nullable', 'integer', Rule::in([30, 60, 90, 180, 365])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $productId = isset($filters['product_id']) ? (int) $filters['product_id'] : null;
        $slowDays = isset($filters['slow_days']) ? (int) $filters['slow_days'] : 60;

        return view('inventory.intelligence.index', [
            'warehouses' => Warehouse::query()
                ->orderByDesc('is_default')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'products' => Product::query()
                ->where('type', ProductType::Product->value)
                ->orderBy('name')
                ->get(),
            'filters' => [
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'slow_days' => $slowDays,
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
            ],
            'data' => $intelligence->dashboard(
                $warehouseId,
                $productId,
                $slowDays,
                $filters['date_from'] ?? null,
                $filters['date_to'] ?? null,
            ),
            'baseCurrency' => $currencies->baseCurrency(),
        ]);
    }

    public function refreshAlerts(
        BusinessContext $context,
        InventoryLowStockAlertService $alerts,
    ): RedirectResponse {
        $result = $alerts->scanBusiness((int) $context->currentId());

        return back()->with('status', __('operations.inventory_intelligence.alerts_refreshed', [
            'count' => $result['attention_items'],
        ]));
    }
}
