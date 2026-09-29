<?php

namespace App\Services;

use App\Models\InventoryReservation;
use App\Models\StockMovement;
use App\Support\Decimal;
use Illuminate\Support\Collection;

class InventoryAvailabilityService
{
    public function __construct(
        private readonly InventoryValuationService $valuation,
    ) {
        //
    }

    /**
     * @return array{on_hand:string,reserved:string,available:string}
     */
    public function snapshot(
        int $warehouseId,
        int $productId,
        ?int $variantId = null,
        ?int $locationId = null,
    ): array {
        $onHand = $this->valuation->snapshot(
            $warehouseId,
            $productId,
            $variantId,
            $locationId,
        )['quantity'];

        $reserved = $this->reservedQuantity($warehouseId, $productId, $variantId, $locationId);
        $available = Decimal::min(Decimal::sub($onHand, $reserved), '0');

        return [
            'on_hand' => $onHand,
            'reserved' => $reserved,
            'available' => $available,
        ];
    }

    public function reservedQuantity(
        int $warehouseId,
        int $productId,
        ?int $variantId = null,
        ?int $locationId = null,
    ): string {
        $query = InventoryReservation::query()
            ->effective()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId);

        $variantId === null
            ? $query->whereNull('product_variant_id')
            : $query->where('product_variant_id', $variantId);

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        return Decimal::normalize((string) $query->sum('quantity'));
    }

    /**
     * @return Collection<string,float>
     */
    public function mapForLocation(int $warehouseId, int $locationId): Collection
    {
        $onHand = StockMovement::query()
            ->where('warehouse_id', $warehouseId)
            ->where('location_id', $locationId)
            ->selectRaw('product_id, product_variant_id, SUM(quantity) as quantity')
            ->groupBy('product_id', 'product_variant_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->product_id.':'.($row->product_variant_id ?? 0) => Decimal::normalize((string) $row->quantity),
            ]);

        $reserved = InventoryReservation::query()
            ->effective()
            ->where('warehouse_id', $warehouseId)
            ->where('location_id', $locationId)
            ->selectRaw('product_id, product_variant_id, SUM(quantity) as quantity')
            ->groupBy('product_id', 'product_variant_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->product_id.':'.($row->product_variant_id ?? 0) => Decimal::normalize((string) $row->quantity),
            ]);

        return $onHand->map(function (string $quantity, string $key) use ($reserved): float {
            $available = Decimal::min(
                Decimal::sub($quantity, (string) $reserved->get($key, '0.0000')),
                '0',
            );

            return (float) $available;
        });
    }
}
