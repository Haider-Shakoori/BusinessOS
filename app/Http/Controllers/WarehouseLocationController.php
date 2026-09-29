<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\BusinessContext;
use App\Services\WarehouseLocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class WarehouseLocationController extends Controller
{
    public function index(Request $request, BusinessContext $context): View
    {
        $filters = $request->validate([
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where('business_id', $context->currentId()),
            ],
        ]);

        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;

        $locations = WarehouseLocation::query()
            ->with(['warehouse', 'parent'])
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->orderBy('warehouse_id')
            ->orderByDesc('is_default')
            ->orderBy('parent_id')
            ->orderBy('code')
            ->get();

        $balances = StockMovement::query()
            ->selectRaw('location_id, product_id, product_variant_id, SUM(quantity) as quantity')
            ->with(['location.warehouse', 'product', 'variant'])
            ->whereNotNull('location_id')
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->groupBy('location_id', 'product_id', 'product_variant_id')
            ->havingRaw('SUM(quantity) <> 0')
            ->orderBy('location_id')
            ->orderBy('product_id')
            ->limit(500)
            ->get();

        return view('inventory.locations.index', [
            'warehouses' => Warehouse::query()
                ->orderByDesc('is_default')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
            'locations' => $locations,
            'balances' => $balances,
            'selectedWarehouseId' => $warehouseId,
            'types' => WarehouseLocationService::TYPES,
        ]);
    }

    public function store(
        Request $request,
        BusinessContext $context,
        WarehouseLocationService $service,
    ): RedirectResponse {
        $data = $request->validate([
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where('business_id', $context->currentId()),
            ],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')
                    ->where('business_id', $context->currentId())
                    ->where('warehouse_id', $request->integer('warehouse_id')),
            ],
            'code' => [
                'required',
                'string',
                'max:80',
                Rule::unique('warehouse_locations', 'code')->where('warehouse_id', $request->integer('warehouse_id')),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(WarehouseLocationService::TYPES)],
            'is_default' => ['nullable', 'boolean'],
        ]);

        try {
            $service->create(Warehouse::findOrFail($data['warehouse_id']), $data);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['location' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', __('operations.locations.messages.created'));
    }

    public function update(
        Request $request,
        WarehouseLocation $warehouseLocation,
        BusinessContext $context,
        WarehouseLocationService $service,
    ): RedirectResponse {
        abort_unless($warehouseLocation->business_id === $context->currentId(), 404);

        $data = $request->validate([
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouse_locations', 'id')
                    ->where('business_id', $context->currentId())
                    ->where('warehouse_id', $warehouseLocation->warehouse_id),
            ],
            'code' => [
                'required',
                'string',
                'max:80',
                Rule::unique('warehouse_locations', 'code')
                    ->where('warehouse_id', $warehouseLocation->warehouse_id)
                    ->ignore($warehouseLocation->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(WarehouseLocationService::TYPES)],
        ]);

        try {
            $service->update($warehouseLocation, $data);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['location' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', __('operations.locations.messages.updated'));
    }

    public function setDefault(
        WarehouseLocation $warehouseLocation,
        WarehouseLocationService $service,
    ): RedirectResponse {
        try {
            $service->setDefault($warehouseLocation);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['location' => $exception->getMessage()]);
        }

        return back()->with('status', __('operations.locations.messages.default_set'));
    }

    public function setActive(
        Request $request,
        WarehouseLocation $warehouseLocation,
        WarehouseLocationService $service,
    ): RedirectResponse {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        try {
            $service->setActive($warehouseLocation, (bool) $data['is_active']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['location' => $exception->getMessage()]);
        }

        return back()->with('status', __('operations.locations.messages.status_updated'));
    }

    public function destroy(
        WarehouseLocation $warehouseLocation,
        WarehouseLocationService $service,
    ): RedirectResponse {
        try {
            $service->delete($warehouseLocation);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['location' => $exception->getMessage()]);
        }

        return back()->with('status', __('operations.locations.messages.deleted'));
    }
}
