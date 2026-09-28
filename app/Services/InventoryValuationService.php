<?php

namespace App\Services;

use App\Models\StockMovement;
use App\Support\Decimal;

class InventoryValuationService
{
    /**
     * @return array{quantity:string, stock_value:string, average_unit_cost:string, complete:bool}
     */
    public function snapshot(int $warehouseId, int $productId, ?int $variantId = null): array
    {
        $query = StockMovement::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId);

        $variantId === null
            ? $query->whereNull('product_variant_id')
            : $query->where('product_variant_id', $variantId);

        $movements = $query
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['quantity', 'unit_cost']);

        $quantity = '0.0000';
        $value = '0.0000';
        $complete = true;
        $lastKnownCost = null;

        foreach ($movements as $movement) {
            $movementQuantity = Decimal::normalize((string) $movement->quantity);
            $unitCost = $movement->unit_cost !== null
                ? Decimal::normalize((string) $movement->unit_cost)
                : null;

            if ($unitCost === null && Decimal::lt($movementQuantity, '0') && Decimal::gt($quantity, '0')) {
                $unitCost = Decimal::mulDiv($value, '1', $quantity);
            }

            if ($unitCost === null) {
                $complete = false;
            } else {
                if (Decimal::gt($unitCost, '0')) {
                    $lastKnownCost = $unitCost;
                }

                $value = Decimal::add(
                    $value,
                    Decimal::round(Decimal::mul($movementQuantity, $unitCost)),
                );
            }

            $quantity = Decimal::add($quantity, $movementQuantity);
        }

        if (Decimal::lte($quantity, '0')) {
            return [
                'quantity' => $quantity,
                'stock_value' => '0.0000',
                'average_unit_cost' => '0.0000',
                'complete' => $complete && Decimal::eq($quantity, '0'),
            ];
        }

        $value = Decimal::min($value, '0.0000');
        $averageUnitCost = Decimal::mulDiv($value, '1', $quantity);

        if (! $complete && $lastKnownCost !== null) {
            $averageUnitCost = $lastKnownCost;
        }

        return [
            'quantity' => $quantity,
            'stock_value' => $value,
            'average_unit_cost' => $averageUnitCost,
            'complete' => $complete,
        ];
    }

    public function averageUnitCost(int $warehouseId, int $productId, ?int $variantId = null): string
    {
        return $this->snapshot($warehouseId, $productId, $variantId)['average_unit_cost'];
    }
}
