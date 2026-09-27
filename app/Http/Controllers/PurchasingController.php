<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\GoodsReceiptService;
use App\Services\ProductVariantService;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PurchasingController extends Controller
{
    public function index(GoodsReceiptService $receipts): View
    {
        $orders = PurchaseOrder::query()
            ->with([
                'supplier',
                'requisition',
                'rfq',
                'supplierQuotation',
                'items.product',
                'items.variant',
                'goodsReceipts.warehouse',
                'goodsReceipts.receiver',
                'goodsReceipts.items',
            ])
            ->latest('id')
            ->limit(50)
            ->get();

        return view('purchasing.index', [
            'suppliers' => Supplier::query()->orderBy('name')->get(),
            'products' => Product::query()->with(['variants' => fn ($query) => $query->where('is_active', true)->orderBy('name')])->orderBy('name')->get(),
            'warehouses' => Warehouse::query()->orderBy('name')->get(),
            'orders' => $orders,
            'remainingByOrder' => $orders->mapWithKeys(fn (PurchaseOrder $order): array => [
                $order->id => $receipts->remainingQuantities($order),
            ]),
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

        $supplier = Supplier::create($data + ['is_active' => true]);

        if (empty($supplier->code)) {
            $supplier->update([
                'code' => 'SUP-'.str_pad((string) $supplier->id, 6, '0', STR_PAD_LEFT),
            ]);
        }

        return back()->with('status', __('operations.purchasing.supplier_created'));
    }

    public function receive(
        Request $request,
        PurchaseOrder $purchaseOrder,
        BusinessContext $context,
        GoodsReceiptService $receipts,
    ): RedirectResponse {
        $data = $request->validate([
            'warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('business_id', $context->currentId())],
            'receipt_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required_with:items', 'integer'],
            'items.*.quantity' => ['required_with:items', 'numeric', 'gte:0'],
        ]);

        $receipts->receive(
            $purchaseOrder,
            (int) $data['warehouse_id'],
            $data['items'] ?? [],
            (int) $request->user()->id,
            $data['receipt_date'] ?? now()->toDateString(),
            $data['notes'] ?? null,
        );

        return back()->with('status', __('operations.purchasing.order_received'));
    }

    public function storeOrder(Request $request, BusinessContext $context, ProductVariantService $variants): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('business_id', $context->currentId())],
            'number' => ['required', 'string', 'max:80', Rule::unique('purchase_orders', 'number')->where('business_id', $context->currentId())],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'product_id' => ['required', Rule::exists('products', 'id')->where('business_id', $context->currentId())],
            'product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('business_id', $context->currentId())],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
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

        DB::transaction(function () use ($data, $variant): void {
            $lineTotal = Decimal::round(Decimal::mul((string) $data['quantity'], (string) $data['unit_cost']));

            $order = PurchaseOrder::create([
                'supplier_id' => $data['supplier_id'],
                'number' => $data['number'],
                'status' => 'draft',
                'ap_recognition' => 'invoice',
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'subtotal' => $lineTotal,
                'total' => $lineTotal,
                'notes' => $data['notes'] ?? null,
            ]);

            $order->items()->create([
                'product_id' => $data['product_id'],
                'product_variant_id' => $variant?->id,
                'quantity' => $data['quantity'],
                'unit_cost' => $data['unit_cost'],
                'line_total' => $lineTotal,
            ]);
        });

        return back()->with('status', __('operations.purchasing.order_created'));
    }
}
