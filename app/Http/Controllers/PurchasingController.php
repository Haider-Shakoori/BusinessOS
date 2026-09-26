<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchasingController extends Controller
{
    public function index(): View
    {
        return view('purchasing.index', [
            'suppliers' => Supplier::query()->orderBy('name')->get(),
            'products' => Product::query()->orderBy('name')->get(),
            'orders' => PurchaseOrder::query()->with(['supplier', 'items.product'])->latest('id')->limit(50)->get(),
        ]);
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:2000'],
        ]);

        Supplier::create($data + ['is_active' => true]);

        return back()->with('status', __('operations.purchasing.supplier_created'));
    }

    public function storeOrder(Request $request, BusinessContext $context): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('business_id', $context->currentId())],
            'number' => ['required', 'string', 'max:80', Rule::unique('purchase_orders', 'number')->where('business_id', $context->currentId())],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $context->currentId())],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($data): void {
            $lineTotal = round((float) $data['quantity'] * (float) $data['unit_cost'], 4);

            $order = PurchaseOrder::create([
                'supplier_id' => $data['supplier_id'],
                'number' => $data['number'],
                'status' => 'draft',
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'subtotal' => $lineTotal,
                'total' => $lineTotal,
                'notes' => $data['notes'] ?? null,
            ]);

            $order->items()->create([
                'product_id' => $data['product_id'],
                'quantity' => $data['quantity'],
                'unit_cost' => $data['unit_cost'],
                'line_total' => $lineTotal,
            ]);
        });

        return back()->with('status', __('operations.purchasing.order_created'));
    }
}
