<?php

namespace App\Http\Controllers;

use App\Models\Bom;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ManufacturingController extends Controller
{
    public function index(): View
    {
        return view('manufacturing.index', [
            'products' => Product::query()->orderBy('name')->get(),
            'boms' => Bom::query()->with(['product', 'items.material'])->latest('id')->get(),
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'orders' => ProductionOrder::query()->with(['product', 'bom'])->latest('id')->limit(50)->get(),
        ]);
    }

    public function storeBom(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $context->currentId())],
            'code' => ['required', 'string', 'max:80', Rule::unique('boms', 'code')->where('business_id', $context->currentId())],
            'version' => ['required', 'string', 'max:30'],
            'material_product_id' => ['required', 'different:product_id', Rule::exists('products', 'id')->where('business_id', $context->currentId())],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($data): void {
            $bom = Bom::create([
                'product_id' => $data['product_id'],
                'code' => $data['code'],
                'version' => $data['version'],
                'is_active' => true,
                'notes' => $data['notes'] ?? null,
            ]);

            $bom->items()->create([
                'material_product_id' => $data['material_product_id'],
                'quantity' => $data['quantity'],
                'wastage_percent' => $data['wastage_percent'] ?? 0,
            ]);
        });

        return back()->with('status', __('operations.manufacturing.bom_created'));
    }

    public function complete(Request $request, ProductionOrder $productionOrder, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('business_id', $context->currentId())],
            'actual_quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        DB::transaction(function () use ($productionOrder, $data): void {
            $order = ProductionOrder::query()->with('bom.items')->lockForUpdate()->findOrFail($productionOrder->id);

            if ($order->status === 'completed') {
                return;
            }

            if ($order->bom) {
                foreach ($order->bom->items as $item) {
                    $factor = 1 + ((float) $item->wastage_percent / 100);
                    $consumption = round((float) $item->quantity * (float) $data['actual_quantity'] * $factor, 4);

                    StockMovement::create([
                        'warehouse_id' => $data['warehouse_id'],
                        'product_id' => $item->material_product_id,
                        'type' => 'production_out',
                        'quantity' => -$consumption,
                        'reference_type' => ProductionOrder::class,
                        'reference_id' => $order->id,
                        'note' => $order->number,
                        'occurred_at' => now(),
                    ]);
                }
            }

            StockMovement::create([
                'warehouse_id' => $data['warehouse_id'],
                'product_id' => $order->product_id,
                'type' => 'production_in',
                'quantity' => $data['actual_quantity'],
                'reference_type' => ProductionOrder::class,
                'reference_id' => $order->id,
                'note' => $order->number,
                'occurred_at' => now(),
            ]);

            $order->update([
                'status' => 'completed',
                'actual_quantity' => $data['actual_quantity'],
                'completed_at' => now(),
            ]);
        });

        return back()->with('status', __('operations.manufacturing.order_completed'));
    }

    public function storeOrder(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'bom_id' => ['nullable', Rule::exists('boms', 'id')->where('business_id', $context->currentId())],
            'product_id' => ['required', Rule::exists('products', 'id')],
            'number' => ['required', 'string', 'max:80', Rule::unique('production_orders', 'number')->where('business_id', $context->currentId())],
            'planned_quantity' => ['required', 'numeric', 'gt:0'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        ProductionOrder::create($data + ['status' => 'planned']);

        return back()->with('status', __('operations.manufacturing.order_created'));
    }
}
