<?php

namespace App\Services;

use App\Models\StockMovement;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class InventoryIntelligenceService
{
    public function __construct(
        private readonly InventoryValuationService $valuation,
        private readonly InventoryReorderService $reorder,
    ) {
        //
    }

    /**
     * @return array<string,mixed>
     */
    public function dashboard(
        ?int $warehouseId = null,
        ?int $productId = null,
        int $slowDays = 60,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): array {
        $slowDays = max(1, min(3650, $slowDays));
        $dateFrom ??= now()->startOfMonth()->toDateString();
        $dateTo ??= now()->toDateString();

        $groups = StockMovement::query()
            ->selectRaw('warehouse_id, product_id, product_variant_id, SUM(quantity) as quantity, MAX(occurred_at) as last_movement_at')
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->when($productId !== null, fn ($query) => $query->where('product_id', $productId))
            ->groupBy('warehouse_id', 'product_id', 'product_variant_id')
            ->havingRaw('SUM(quantity) <> 0')
            ->with(['warehouse', 'product', 'variant'])
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->limit(1000)
            ->get();

        $totalQuantity = '0.0000';
        $totalValue = '0.0000';
        $incompleteValuations = 0;
        $slowMoving = 0;
        $cutoff = CarbonImmutable::now()->subDays($slowDays);

        $valuationRows = $groups->map(function (StockMovement $group) use (
            &$totalQuantity,
            &$totalValue,
            &$incompleteValuations,
            &$slowMoving,
            $cutoff,
        ): array {
            $variantId = $group->product_variant_id !== null
                ? (int) $group->product_variant_id
                : null;

            $snapshot = $this->valuation->snapshot(
                (int) $group->warehouse_id,
                (int) $group->product_id,
                $variantId,
            );

            $lastMovement = $group->last_movement_at !== null
                ? CarbonImmutable::parse((string) $group->last_movement_at)
                : null;
            $inactiveDays = $lastMovement?->diffInDays(now()) ?? 0;
            $isSlow = Decimal::gt($snapshot['quantity'], '0')
                && $lastMovement !== null
                && $lastMovement->lessThanOrEqualTo($cutoff);

            $totalQuantity = Decimal::add($totalQuantity, $snapshot['quantity']);
            $totalValue = Decimal::add($totalValue, $snapshot['stock_value']);

            if (! $snapshot['complete']) {
                $incompleteValuations++;
            }

            if ($isSlow) {
                $slowMoving++;
            }

            return [
                'warehouse' => $group->warehouse,
                'product' => $group->product,
                'variant' => $group->variant,
                'quantity' => $snapshot['quantity'],
                'average_unit_cost' => $snapshot['average_unit_cost'],
                'stock_value' => $snapshot['stock_value'],
                'valuation_complete' => $snapshot['complete'],
                'last_movement_at' => $lastMovement,
                'inactive_days' => $inactiveDays,
                'is_slow' => $isSlow,
            ];
        });

        $movementQuery = StockMovement::query()
            ->with(['warehouse', 'product', 'variant'])
            ->whereDate('occurred_at', '>=', $dateFrom)
            ->whereDate('occurred_at', '<=', $dateTo)
            ->when($warehouseId !== null, fn ($query) => $query->where('warehouse_id', $warehouseId))
            ->when($productId !== null, fn ($query) => $query->where('product_id', $productId));

        $movementRows = (clone $movementQuery)
            ->latest('occurred_at')
            ->latest('id')
            ->limit(250)
            ->get();

        $inboundQuantity = '0.0000';
        $outboundQuantity = '0.0000';
        $netMovement = '0.0000';

        foreach ((clone $movementQuery)->get(['quantity']) as $movement) {
            $quantity = Decimal::normalize((string) $movement->quantity);
            $netMovement = Decimal::add($netMovement, $quantity);

            if (Decimal::gt($quantity, '0')) {
                $inboundQuantity = Decimal::add($inboundQuantity, $quantity);
            } elseif (Decimal::lt($quantity, '0')) {
                $outboundQuantity = Decimal::add(
                    $outboundQuantity,
                    Decimal::sub('0.0000', $quantity),
                );
            }
        }

        $reorderRows = $this->reorder->overview($warehouseId);

        return [
            'valuation_rows' => $valuationRows,
            'movements' => $movementRows,
            'summary' => [
                'stock_quantity' => $totalQuantity,
                'stock_value' => $totalValue,
                'incomplete_valuations' => $incompleteValuations,
                'slow_moving' => $slowMoving,
                'out_of_stock' => $reorderRows->where('status', 'out_of_stock')->count(),
                'low_stock' => $reorderRows->where('status', 'low')->count(),
                'replenishing' => $reorderRows->where('status', 'replenishing')->count(),
                'inbound_quantity' => $inboundQuantity,
                'outbound_quantity' => $outboundQuantity,
                'net_movement' => $netMovement,
            ],
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'slow_days' => $slowDays,
        ];
    }
}
