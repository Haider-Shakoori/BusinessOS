<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryCountService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly InventoryValuationService $valuation,
        private readonly AccountingPostingService $accounting,
    ) {
        //
    }

    public function create(
        Warehouse $warehouse,
        string $countDate,
        ?string $notes,
        int $userId,
    ): InventoryCount {
        return DB::transaction(function () use ($warehouse, $countDate, $notes, $userId): InventoryCount {
            $count = InventoryCount::create([
                'warehouse_id' => $warehouse->id,
                'number' => $this->numbers->next(DocumentType::InventoryCount),
                'status' => 'draft',
                'count_date' => $countDate,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            $groups = StockMovement::query()
                ->where('warehouse_id', $warehouse->id)
                ->selectRaw('product_id, product_variant_id, SUM(quantity) as quantity')
                ->groupBy('product_id', 'product_variant_id')
                ->havingRaw('SUM(quantity) <> 0')
                ->get();

            foreach ($groups as $group) {
                $variantId = $group->product_variant_id !== null ? (int) $group->product_variant_id : null;
                $snapshot = $this->valuation->snapshot($warehouse->id, (int) $group->product_id, $variantId);
                $count->items()->create([
                    'product_id' => $group->product_id,
                    'product_variant_id' => $variantId,
                    'expected_quantity' => $snapshot['quantity'],
                    'unit_cost' => $snapshot['average_unit_cost'],
                ]);
            }

            return $count->load(['warehouse', 'items.product', 'items.variant']);
        });
    }

    public function addLine(
        InventoryCount $count,
        Product $product,
        ?ProductVariant $variant,
        ?string $unitCost = null,
    ): InventoryCountItem {
        return DB::transaction(function () use ($count, $product, $variant, $unitCost): InventoryCountItem {
            $locked = InventoryCount::query()->lockForUpdate()->findOrFail($count->id);

            if ($locked->status !== 'draft') {
                throw new RuntimeException(__('operations.stock_counts.errors.not_draft'));
            }

            $duplicate = $locked->items()
                ->where('product_id', $product->id)
                ->when(
                    $variant === null,
                    fn ($query) => $query->whereNull('product_variant_id'),
                    fn ($query) => $query->where('product_variant_id', $variant->id),
                )
                ->exists();

            if ($duplicate) {
                throw new RuntimeException(__('operations.stock_counts.errors.duplicate_item'));
            }

            $snapshot = $this->valuation->snapshot($locked->warehouse_id, $product->id, $variant?->id);

            if (! Decimal::isZero($snapshot['quantity'])) {
                throw new RuntimeException(__('operations.stock_counts.errors.stock_changed'));
            }

            $effectiveCost = $unitCost !== null && $unitCost !== ''
                ? Decimal::normalize($unitCost)
                : $snapshot['average_unit_cost'];

            if (Decimal::lt($effectiveCost, '0')) {
                throw new RuntimeException(__('operations.stock_counts.errors.invalid_cost'));
            }

            return $locked->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'expected_quantity' => $snapshot['quantity'],
                'unit_cost' => $effectiveCost,
            ]);
        });
    }

    public function record(InventoryCountItem $item, string $countedQuantity, ?string $unitCost = null): InventoryCountItem
    {
        $counted = Decimal::normalize($countedQuantity);

        if (Decimal::lt($counted, '0')) {
            throw new RuntimeException(__('operations.stock_counts.errors.invalid_count'));
        }

        return DB::transaction(function () use ($item, $counted, $unitCost): InventoryCountItem {
            $lockedItem = InventoryCountItem::query()->lockForUpdate()->findOrFail($item->id);
            $count = InventoryCount::query()->lockForUpdate()->findOrFail($lockedItem->inventory_count_id);

            if ($count->status !== 'draft') {
                throw new RuntimeException(__('operations.stock_counts.errors.not_draft'));
            }
            $effectiveCost = (string) $lockedItem->unit_cost;

            if (Decimal::isZero((string) $lockedItem->expected_quantity)
                && $unitCost !== null && $unitCost !== '') {
                $effectiveCost = Decimal::normalize($unitCost);
            }

            if (Decimal::lt($effectiveCost, '0')) {
                throw new RuntimeException(__('operations.stock_counts.errors.invalid_cost'));
            }

            $variance = Decimal::sub($counted, (string) $lockedItem->expected_quantity);
            $varianceValue = Decimal::round(Decimal::mul($variance, $effectiveCost));

            $lockedItem->update([
                'counted_quantity' => $counted,
                'variance_quantity' => $variance,
                'unit_cost' => $effectiveCost,
                'variance_value' => $varianceValue,
            ]);

            $this->recalculateTotals($count);

            return $lockedItem->refresh();
        });
    }

    public function submit(InventoryCount $count, int $userId): InventoryCount
    {
        return DB::transaction(function () use ($count, $userId): InventoryCount {
            $locked = InventoryCount::query()->lockForUpdate()->findOrFail($count->id);

            if ($locked->status !== 'draft') {
                throw new RuntimeException(__('operations.stock_counts.errors.not_draft'));
            }

            if (! $locked->items()->exists()) {
                throw new RuntimeException(__('operations.stock_counts.errors.empty'));
            }

            if ($locked->items()->whereNull('counted_quantity')->exists()) {
                throw new RuntimeException(__('operations.stock_counts.errors.incomplete'));
            }

            foreach ($locked->items()->get() as $item) {
                if (Decimal::isZero((string) $item->expected_quantity)
                    && Decimal::gt((string) $item->counted_quantity, '0')
                    && ! Decimal::gt((string) $item->unit_cost, '0')) {
                    throw new RuntimeException(__('operations.stock_counts.errors.missing_cost'));
                }
            }

            $this->recalculateTotals($locked);

            $locked->update([
                'status' => 'submitted',
                'submitted_by' => $userId,
                'submitted_at' => now(),
            ]);

            return $locked->refresh()->load(['warehouse', 'items.product', 'items.variant']);
        });
    }

    public function post(InventoryCount $count, int $userId): InventoryCount
    {
        return DB::transaction(function () use ($count, $userId): InventoryCount {
            $locked = InventoryCount::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($count->id);

            if ($locked->status !== 'submitted') {
                throw new RuntimeException(__('operations.stock_counts.errors.not_submitted'));
            }

            foreach ($locked->items as $item) {
                $snapshot = $this->valuation->snapshot(
                    $locked->warehouse_id,
                    $item->product_id,
                    $item->product_variant_id,
                );

                if (! Decimal::eq($snapshot['quantity'], (string) $item->expected_quantity)) {
                    throw new RuntimeException(__('operations.stock_counts.errors.stock_changed'));
                }

                if (! Decimal::isZero((string) $item->expected_quantity)
                    && ! Decimal::eq($snapshot['average_unit_cost'], (string) $item->unit_cost)) {
                    throw new RuntimeException(__('operations.stock_counts.errors.stock_changed'));
                }

                if (! $snapshot['complete'] && ! Decimal::isZero((string) $item->variance_quantity)) {
                    throw new RuntimeException(__('operations.stock_counts.errors.valuation_incomplete'));
                }

                if (Decimal::gt((string) $item->variance_quantity, '0')
                    && ! Decimal::gt((string) $item->unit_cost, '0')) {
                    throw new RuntimeException(__('operations.stock_counts.errors.missing_cost'));
                }
            }

            foreach ($locked->items as $item) {
                if (Decimal::isZero((string) $item->variance_quantity)) {
                    continue;
                }

                StockMovement::create([
                    'warehouse_id' => $locked->warehouse_id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'type' => 'stock_count_adjustment',
                    'quantity' => $item->variance_quantity,
                    'unit_cost' => $item->unit_cost,
                    'reference_type' => InventoryCount::class,
                    'reference_id' => $locked->id,
                    'note' => $locked->number,
                    'occurred_at' => now(),
                ]);
            }

            $this->recalculateTotals($locked);

            $locked->update([
                'status' => 'posted',
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            $this->accounting->postInventoryCount($locked->refresh()->load('items'));

            return $locked->refresh()->load(['warehouse', 'items.product', 'items.variant', 'approver']);
        });
    }

    private function recalculateTotals(InventoryCount $count): void
    {
        $positive = '0.0000';
        $negative = '0.0000';

        foreach ($count->items()->get(['variance_value']) as $item) {
            $value = Decimal::normalize((string) $item->variance_value);

            if (Decimal::gt($value, '0')) {
                $positive = Decimal::add($positive, $value);
            } elseif (Decimal::lt($value, '0')) {
                $negative = Decimal::add($negative, Decimal::sub('0.0000', $value));
            }
        }

        $count->update([
            'total_positive_variance_value' => $positive,
            'total_negative_variance_value' => $negative,
        ]);
    }
}
