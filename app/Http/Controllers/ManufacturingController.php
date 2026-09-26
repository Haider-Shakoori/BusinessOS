<?php

namespace App\Http\Controllers;

use App\Models\Bom;
use App\Models\Product;
use App\Models\ProductionOrder;
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
            'orders' => ProductionOrder::query()->with(['product', 'bom'])->latest('id')->limit(50)->get(),
        ]);
    }

    public function storeBom(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')],
            'code' => ['required', 'string', 'max:80', Rule::unique('boms', 'code')->where('business_id', $context->currentId())],
            'version' => ['required', 'string', 'max:30'],
            'material_product_id' => ['required', 'different:product_id', Rule::exists('products', 'id')],
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

    public function storeOrder(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'bom_id' => ['nullable', Rule::exists('boms', 'id')],
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
