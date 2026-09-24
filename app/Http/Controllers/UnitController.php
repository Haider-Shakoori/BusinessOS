<?php

namespace App\Http\Controllers;

use App\Http\Requests\Unit\StoreUnitRequest;
use App\Http\Requests\Unit\UpdateUnitRequest;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Unit CRUD controller (Batch 11).
 *
 * All list queries are scoped to the current tenant by the BelongsToBusiness
 * global scope (Unit::query()). Route-model binding resolves `{unit}` within
 * the same scope — cross-business lookups return 404 automatically.
 *
 * Controller logic is intentionally thin: no forbidden-user checks here
 * (handled by the route middleware), no manual business_id assignment (handled
 * by the BelongsToBusiness trait on Unit::create).
 */
class UnitController extends Controller
{
    public function index(Request $request): View
    {
        $searchTerm = trim((string) $request->string('search'));

        $units = Unit::query()
            ->when($searchTerm !== '', function ($query) use ($searchTerm) {
                $query->where(function ($query) use ($searchTerm) {
                    $query->where('name', 'like', "%{$searchTerm}%")
                        ->orWhere('short_name', 'like', "%{$searchTerm}%");
                });
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('units.index', compact('units', 'searchTerm'));
    }

    public function create(): View
    {
        return view('units.create');
    }

    public function store(StoreUnitRequest $request): RedirectResponse
    {
        $unit = Unit::create($request->validated());

        return redirect()
            ->route('units.index')
            ->with('status', __('units.created'));
    }

    public function edit(Unit $unit): View
    {
        return view('units.edit', compact('unit'));
    }

    public function update(UpdateUnitRequest $request, Unit $unit): RedirectResponse
    {
        $unit->update($request->validated());

        return redirect()
            ->route('units.index')
            ->with('status', __('units.updated'));
    }

    public function destroy(Unit $unit): RedirectResponse
    {
        $unit->delete();

        return redirect()
            ->route('units.index')
            ->with('status', __('units.deleted'));
    }
}
