<?php

namespace App\Http\Controllers;

use App\Enums\ProductType;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\Tax;
use App\Models\Unit;
use App\Services\BusinessSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Product / service CRUD controller (Batch 12).
 *
 * All list queries are scoped to the current tenant by the BelongsToBusiness
 * global scope (Product::query()). Route-model binding resolves `{product}`
 * within the same scope — cross-business lookups return 404 automatically.
 *
 * Controller logic is intentionally thin: no forbidden-user checks here
 * (handled by the route middleware), no manual business_id assignment (handled
 * by the BelongsToBusiness trait on Product::create). The optional-tax feature
 * gate is resolved once per request via BusinessSettings and drives whether a
 * tax selector is available; when disabled no tax_id reaches validated().
 *
 * The index eager-loads category/unit/tax so the list never re-queries per
 * row. A soft-deleted master record resolves the relation to null and the UI
 * degrades to a dash — the product row itself stays intact.
 */
class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $searchTerm = trim((string) $request->string('search'));
        $typeFilter = ProductType::tryFrom((string) $request->string('type'))?->value;

        $products = Product::query()
            ->with(['category', 'unit', 'tax'])
            ->when($searchTerm !== '', function ($query) use ($searchTerm) {
                $query->where(function ($query) use ($searchTerm) {
                    $query->where('name', 'like', "%{$searchTerm}%")
                        ->orWhere('sku', 'like', "%{$searchTerm}%");
                });
            })
            ->when($typeFilter !== null, function ($query) use ($typeFilter) {
                $query->where('type', $typeFilter);
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('products.index', compact('products', 'searchTerm', 'typeFilter'));
    }

    public function create(): View
    {
        $taxesEnabled = $this->taxesEnabled();

        return view('products.create', [
            'categories' => $this->selectableCategories(),
            'units' => $this->selectableUnits(),
            'taxesEnabled' => $taxesEnabled,
            'taxes' => $taxesEnabled ? $this->selectableTaxes() : collect(),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        Product::create($request->validated());

        return redirect()
            ->route('products.index')
            ->with('status', __('products.created'));
    }

    public function edit(Product $product): View
    {
        $taxesEnabled = $this->taxesEnabled();

        return view('products.edit', [
            'product' => $product,
            'categories' => $this->selectableCategories(),
            'units' => $this->selectableUnits(),
            'taxesEnabled' => $taxesEnabled,
            'taxes' => $taxesEnabled ? $this->selectableTaxes() : collect(),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $product->update($request->validated());

        return redirect()
            ->route('products.index')
            ->with('status', __('products.updated'));
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return redirect()
            ->route('products.index')
            ->with('status', __('products.deleted'));
    }

    /**
     * Active (non soft-deleted) categories of the CURRENT business only.
     */
    private function selectableCategories()
    {
        return Category::query()->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Active (non soft-deleted) units of the CURRENT business only.
     */
    private function selectableUnits()
    {
        return Unit::query()->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Active (non soft-deleted) taxes of the CURRENT business only. Only used
     * while general.tax_enabled is on — offered options never include
     * soft-deleted or foreign-business rows.
     */
    private function selectableTaxes()
    {
        return Tax::query()->orderBy('name')->orderBy('id')->get();
    }

    private function taxesEnabled(): bool
    {
        return (bool) app(BusinessSettings::class)->get('general.tax_enabled', false);
    }
}
