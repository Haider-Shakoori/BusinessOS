<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tax\StoreTaxRequest;
use App\Http\Requests\Tax\UpdateTaxRequest;
use App\Models\Tax;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tax CRUD controller (Batch 11).
 *
 * All list queries are scoped to the current tenant by the BelongsToBusiness
 * global scope (Tax::query()). Route-model binding resolves `{tax}` within the
 * same scope — cross-business lookups return 404 automatically.
 *
 * Controller logic is intentionally thin: no forbidden-user checks here
 * (handled by the route middleware), no manual business_id assignment (handled
 * by the BelongsToBusiness trait on Tax::create). The optional-tax feature
 * gate (`tax-enabled` middleware) lives entirely at the route layer; this
 * controller never checks `tax_enabled` or tax row counts itself.
 */
class TaxController extends Controller
{
    public function index(Request $request): View
    {
        $searchTerm = trim((string) $request->string('search'));

        $taxes = Tax::query()
            ->when($searchTerm !== '', function ($query) use ($searchTerm) {
                $query->where('name', 'like', "%{$searchTerm}%");
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('taxes.index', compact('taxes', 'searchTerm'));
    }

    public function create(): View
    {
        return view('taxes.create');
    }

    public function store(StoreTaxRequest $request): RedirectResponse
    {
        $tax = Tax::create($request->validated());

        return redirect()
            ->route('taxes.index')
            ->with('status', __('taxes.created'));
    }

    public function edit(Tax $tax): View
    {
        return view('taxes.edit', compact('tax'));
    }

    public function update(UpdateTaxRequest $request, Tax $tax): RedirectResponse
    {
        $tax->update($request->validated());

        return redirect()
            ->route('taxes.index')
            ->with('status', __('taxes.updated'));
    }

    public function destroy(Tax $tax): RedirectResponse
    {
        $tax->delete();

        return redirect()
            ->route('taxes.index')
            ->with('status', __('taxes.deleted'));
    }
}
