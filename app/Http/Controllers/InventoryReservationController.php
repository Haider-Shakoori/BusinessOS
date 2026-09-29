<?php

namespace App\Http\Controllers;

use App\Enums\ProductType;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Services\BusinessContext;
use App\Services\InventoryAvailabilityService;
use App\Services\InventoryReservationService;
use App\Services\ProductVariantService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class InventoryReservationController extends Controller
{
    public function index(
        Request $request,
        BusinessContext $context,
        InventoryAvailabilityService $availability,
    ): View {
        $filters = $request->validate([
            'warehouse_id' => [
                'nullable',
                'integer',
                Rule::exists('warehouses', 'id')->where('business_id', $context->currentId()),
            ],
            'status' => ['nullable', Rule::in(['active', 'released', 'consumed', 'expired'])],
        ]);

        $warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $status = $filters['status'] ?? null;

        $reservations = InventoryReservation::query()
            ->with(['warehouse', 'location', 'product', 'variant', 'creator'])
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->limit(150)
            ->get();

        $reservations->each(function (InventoryReservation $reservation) use ($availability): void {
            $snapshot = $availability->snapshot(
                $reservation->warehouse_id,
                $reservation->product_id,
                $reservation->product_variant_id,
                $reservation->location_id,
            );

            $reservation->setAttribute('on_hand_quantity', $snapshot['on_hand']);
            $reservation->setAttribute('reserved_quantity', $snapshot['reserved']);
            $reservation->setAttribute('available_quantity', $snapshot['available']);
        });

        return view('inventory.reservations.index', [
            'reservations' => $reservations,
            'warehouses' => Warehouse::query()
                ->where('is_active', true)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(),
            'locations' => WarehouseLocation::query()
                ->where('is_active', true)
                ->with('warehouse')
                ->orderBy('warehouse_id')
                ->orderByDesc('is_default')
                ->orderBy('code')
                ->get(),
            'products' => Product::query()
                ->where('type', ProductType::Product->value)
                ->with(['variants' => fn ($query) => $query->where('is_active', true)->orderBy('name')])
                ->orderBy('name')
                ->get(),
            'selectedWarehouseId' => $warehouseId,
            'selectedStatus' => $status,
            'statuses' => ['active', 'released', 'consumed', 'expired'],
        ]);
    }

    public function store(
        Request $request,
        BusinessContext $context,
        InventoryReservationService $service,
        ProductVariantService $variants,
    ): RedirectResponse {
        $data = $request->validate([
            'location_id' => [
                'required',
                'integer',
                Rule::exists('warehouse_locations', 'id')
                    ->where('business_id', $context->currentId())
                    ->where('is_active', true),
            ],
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')
                    ->where('business_id', $context->currentId())
                    ->whereNull('deleted_at'),
            ],
            'product_variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')
                    ->where('business_id', $context->currentId())
                    ->where('is_active', true),
            ],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $product = Product::findOrFail($data['product_id']);

        try {
            $variant = $variants->resolve(
                $product,
                isset($data['product_variant_id']) ? (int) $data['product_variant_id'] : null,
            );

            $service->create(
                WarehouseLocation::findOrFail($data['location_id']),
                $product,
                $variant,
                (string) $data['quantity'],
                isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
                $data['note'] ?? null,
                null,
                null,
                $request->user()?->id,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['reservation' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', __('operations.reservations.messages.created'));
    }

    public function release(
        InventoryReservation $inventoryReservation,
        InventoryReservationService $service,
    ): RedirectResponse {
        try {
            $service->release($inventoryReservation);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['reservation' => $exception->getMessage()]);
        }

        return back()->with('status', __('operations.reservations.messages.released'));
    }
}
