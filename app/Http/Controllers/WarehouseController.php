<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Services\BusinessContext;
use App\Services\WarehouseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class WarehouseController extends Controller
{
    public function index(): View
    {
        return view('inventory.warehouses.index', [
            'warehouses' => Warehouse::query()
                ->orderByDesc('is_default')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, BusinessContext $context, WarehouseService $service): RedirectResponse
    {
        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('warehouses', 'code')->where('business_id', $context->currentId()),
            ],
            'name' => ['required', 'string', 'max:255'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $service->create($data + ['is_default' => $request->boolean('is_default')]);

        return redirect()->route('inventory.warehouses.index')
            ->with('status', __('operations.warehouses.messages.created'));
    }

    public function update(
        Request $request,
        Warehouse $warehouse,
        BusinessContext $context,
        WarehouseService $service,
    ): RedirectResponse {
        $data = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('warehouses', 'code')
                    ->where('business_id', $context->currentId())
                    ->ignore($warehouse->id),
            ],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $service->update($warehouse, $data);

        return back()->with('status', __('operations.warehouses.messages.updated'));
    }

    public function setDefault(Warehouse $warehouse, WarehouseService $service): RedirectResponse
    {
        try {
            $service->setDefault($warehouse);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['warehouse' => $exception->getMessage()]);
        }

        return back()->with('status', __('operations.warehouses.messages.default_set'));
    }

    public function setActive(Request $request, Warehouse $warehouse, WarehouseService $service): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        try {
            $service->setActive($warehouse, (bool) $data['is_active']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['warehouse' => $exception->getMessage()]);
        }

        return back()->with('status', __(
            $data['is_active']
                ? 'operations.warehouses.messages.activated'
                : 'operations.warehouses.messages.deactivated',
        ));
    }

    public function destroy(Warehouse $warehouse, WarehouseService $service): RedirectResponse
    {
        try {
            $service->delete($warehouse);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['warehouse' => $exception->getMessage()]);
        }

        return back()->with('status', __('operations.warehouses.messages.deleted'));
    }
}
