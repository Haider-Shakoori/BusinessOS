<?php

namespace App\Services;

use App\Enums\ProductType;
use App\Models\InventoryReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseLocation;
use App\Support\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryReservationService
{
    public function __construct(
        private readonly InventoryAvailabilityService $availability,
        private readonly WarehouseLocationService $locations,
    ) {
        //
    }

    public function create(
        WarehouseLocation $location,
        Product $product,
        ?ProductVariant $variant,
        string $quantity,
        ?CarbonInterface $expiresAt,
        ?string $note,
        ?string $referenceType,
        ?int $referenceId,
        ?int $userId,
    ): InventoryReservation {
        $quantity = Decimal::normalize($quantity);

        if (! Decimal::gt($quantity, '0')) {
            throw new RuntimeException(__('operations.reservations.errors.invalid_quantity'));
        }

        if ($product->type !== ProductType::Product) {
            throw new RuntimeException(__('operations.reservations.errors.physical_product_only'));
        }

        if ($expiresAt !== null && $expiresAt->lte(now())) {
            throw new RuntimeException(__('operations.reservations.errors.invalid_expiry'));
        }

        $location = $this->locations->forMovement($location->warehouse_id, $location->id);

        return DB::transaction(function () use (
            $location,
            $product,
            $variant,
            $quantity,
            $expiresAt,
            $note,
            $referenceType,
            $referenceId,
            $userId,
        ): InventoryReservation {
            Product::query()->lockForUpdate()->findOrFail($product->id);

            $snapshot = $this->availability->snapshot(
                $location->warehouse_id,
                $product->id,
                $variant?->id,
                $location->id,
            );

            if (Decimal::lt($snapshot['available'], $quantity)) {
                throw new RuntimeException(__('operations.reservations.errors.insufficient_available', [
                    'available' => $snapshot['available'],
                ]));
            }

            return InventoryReservation::create([
                'warehouse_id' => $location->warehouse_id,
                'location_id' => $location->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity' => $quantity,
                'status' => 'active',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'note' => $note,
                'expires_at' => $expiresAt,
                'created_by' => $userId,
            ]);
        });
    }

    public function release(InventoryReservation $reservation): InventoryReservation
    {
        return DB::transaction(function () use ($reservation): InventoryReservation {
            $locked = InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status !== 'active') {
                throw new RuntimeException(__('operations.reservations.errors.not_active'));
            }

            $locked->update([
                'status' => 'released',
                'released_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    public function consume(InventoryReservation $reservation): InventoryReservation
    {
        return DB::transaction(function () use ($reservation): InventoryReservation {
            $locked = InventoryReservation::query()->lockForUpdate()->findOrFail($reservation->id);

            if ($locked->status !== 'active'
                || ($locked->expires_at !== null && $locked->expires_at->lte(now()))) {
                throw new RuntimeException(__('operations.reservations.errors.not_active'));
            }

            $locked->update([
                'status' => 'consumed',
                'consumed_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    /**
     * @return int number of reservations marked expired
     */
    public function expireDue(): int
    {
        return InventoryReservation::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'released_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
