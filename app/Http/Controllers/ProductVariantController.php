<?php

namespace App\Http\Controllers;

use App\Enums\ProductType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Services\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductVariantController extends Controller
{
    public function index(Product $product): View
    {
        abort_unless($product->type === ProductType::Product, 404);

        return view('products.variants', [
            'product' => $product,
            'variants' => $product->variants()->orderBy('name')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request, Product $product, BusinessContext $context): RedirectResponse
    {
        abort_unless($product->type === ProductType::Product, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('product_variants', 'sku')->where('business_id', $context->currentId()),
            ],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product->variants()->create($data + ['is_active' => true]);

        return back()->with('status', __('products.variants.created'));
    }

    public function update(
        Request $request,
        Product $product,
        ProductVariant $productVariant,
        BusinessContext $context,
    ): RedirectResponse {
        abort_unless($productVariant->product_id === $product->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('product_variants', 'sku')
                    ->where('business_id', $context->currentId())
                    ->ignore($productVariant->id),
            ],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $productVariant->update([
            ...$data,
            'is_active' => array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : $productVariant->is_active,
        ]);

        return back()->with('status', __('products.variants.updated'));
    }

    public function destroy(Product $product, ProductVariant $productVariant): RedirectResponse
    {
        abort_unless($productVariant->product_id === $product->id, 404);

        $stock = (float) StockMovement::query()
            ->where('product_variant_id', $productVariant->id)
            ->sum('quantity');

        if (abs($stock) > 0.00001) {
            return back()->withErrors([
                'variant' => __('products.variants.delete_with_stock', [
                    'quantity' => number_format($stock, 4, '.', ''),
                ]),
            ]);
        }

        $productVariant->delete();

        return back()->with('status', __('products.variants.deleted'));
    }
}
