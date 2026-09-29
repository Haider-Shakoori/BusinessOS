<?php

namespace App\Services;

use App\Enums\ProductType;
use App\Models\InventoryReorderRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryReorderService
{
    public function __construct(
        private readonly InventoryValuationService $valuation,
        private readonly InventoryAvailabilityService $availability,
        private readonly PurchaseRequisitionService $requisitions,
    ) {
        //
    }

    public function saveRule(
        Warehouse $warehouse,
        Product $product,
        ?ProductVariant $variant,
        string $reorderPoint,
        string $targetStock,
        bool $active = true,
    ): InventoryReorderRule {
        if ($product->type !== ProductType::Product) {
            throw new RuntimeException(__('operations.reorder.errors.physical_product_only'));
        }

        $point = Decimal::normalize($reorderPoint);
        $target = Decimal::normalize($targetStock);

        if (Decimal::lt($point, '0')) {
            throw new RuntimeException(__('operations.reorder.errors.invalid_point'));
        }

        if (! Decimal::gt($target, $point)) {
            throw new RuntimeException(__('operations.reorder.errors.target_must_exceed_point'));
        }

        return DB::transaction(function () use ($warehouse, $product, $variant, $point, $target, $active): InventoryReorderRule {
            $stockKey = $product->id.':'.($variant?->id ?? 0);
            $rule = InventoryReorderRule::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('stock_key', $stockKey)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'stock_key' => $stockKey,
                'reorder_point' => $point,
                'target_stock' => $target,
                'is_active' => $active,
            ];

            if ($rule === null) {
                return InventoryReorderRule::create($attributes);
            }

            $rule->update($attributes);

            return $rule->refresh();
        });
    }

    public function setActive(InventoryReorderRule $rule, bool $active): InventoryReorderRule
    {
        $rule->update(['is_active' => $active]);

        return $rule->refresh();
    }

    public function delete(InventoryReorderRule $rule): void
    {
        $rule->delete();
    }

    /**
     * @return Collection<int,array{
     *   rule:InventoryReorderRule,
     *   current_quantity:string,
     *   average_unit_cost:string,
     *   planning_unit_cost:string,
     *   status:string,
     *   suggested_quantity:string,
     *   suggested_value:string
     * }>
     */
    public function overview(
        ?int $warehouseId = null,
        ?string $status = null,
        ?string $search = null,
    ): Collection {
        $query = InventoryReorderRule::query()
            ->with(['warehouse', 'product', 'variant'])
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($search !== null && trim($search) !== '', function ($q) use ($search): void {
                $term = trim($search);
                $q->whereHas('product', function ($productQuery) use ($term): void {
                    $productQuery->where(function ($inner) use ($term): void {
                        $inner->where('name', 'like', '%'.$term.'%')
                            ->orWhere('sku', 'like', '%'.$term.'%');
                    });
                });
            })
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->limit(500);

        $rows = $query->get()->map(fn (InventoryReorderRule $rule): array => $this->row($rule));

        if ($status !== null && in_array($status, ['ok', 'low', 'out_of_stock', 'replenishing', 'inactive'], true)) {
            $rows = $rows->filter(fn (array $row): bool => $row['status'] === $status);
        }

        return $rows->values();
    }

    /**
     * @return array{
     *   rule:InventoryReorderRule,
     *   current_quantity:string,
     *   average_unit_cost:string,
     *   planning_unit_cost:string,
     *   status:string,
     *   suggested_quantity:string,
     *   suggested_value:string
     * }
     */
    public function row(InventoryReorderRule $rule): array
    {
        $snapshot = $this->valuation->snapshot(
            $rule->warehouse_id,
            $rule->product_id,
            $rule->product_variant_id,
        );

        $current = $snapshot['quantity'];
        $reserved = $this->availability->reservedQuantity(
            $rule->warehouse_id,
            $rule->product_id,
            $rule->product_variant_id,
        );
        $available = Decimal::min(Decimal::sub($current, $reserved), '0');
        $planningCost = $this->planningCost($rule, $snapshot['average_unit_cost']);
        $pipeline = $this->pipelineQuantity($rule);
        $projected = Decimal::add($available, $pipeline);
        $suggested = '0.0000';

        if ($rule->is_active && Decimal::lte($available, (string) $rule->reorder_point)) {
            $difference = Decimal::sub((string) $rule->target_stock, $projected);
            $suggested = Decimal::gt($difference, '0') ? $difference : '0.0000';
        }

        if (! $rule->is_active) {
            $status = 'inactive';
        } elseif (Decimal::lte($available, (string) $rule->reorder_point)
            && Decimal::gt($pipeline, '0')
            && Decimal::isZero($suggested)) {
            $status = 'replenishing';
        } elseif (Decimal::lte($available, '0')) {
            $status = 'out_of_stock';
        } elseif (Decimal::lte($available, (string) $rule->reorder_point)) {
            $status = 'low';
        } else {
            $status = 'ok';
        }

        return [
            'rule' => $rule,
            'current_quantity' => $current,
            'reserved_quantity' => $reserved,
            'available_quantity' => $available,
            'pipeline_quantity' => $pipeline,
            'projected_quantity' => $projected,
            'average_unit_cost' => $snapshot['average_unit_cost'],
            'planning_unit_cost' => $planningCost,
            'status' => $status,
            'suggested_quantity' => $suggested,
            'suggested_value' => Decimal::round(Decimal::mul($suggested, $planningCost)),
        ];
    }

    public function createRequisition(
        array $ruleIds,
        int $userId,
        ?string $neededBy = null,
    ): PurchaseRequisition {
        $ids = collect($ruleIds)->map(fn ($id): int => (int) $id)->unique()->values();

        return DB::transaction(function () use ($ids, $userId, $neededBy): PurchaseRequisition {
            $rules = InventoryReorderRule::query()
                ->with(['warehouse', 'product', 'variant'])
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if ($rules->count() !== $ids->count()) {
                throw new RuntimeException(__('operations.reorder.errors.invalid_selection'));
            }

            if ($rules->pluck('warehouse_id')->unique()->count() !== 1) {
                throw new RuntimeException(__('operations.reorder.errors.same_warehouse'));
            }

            $items = [];

            foreach ($rules as $rule) {
                $row = $this->row($rule);

                if (! $rule->is_active || ! Decimal::gt($row['suggested_quantity'], '0')) {
                    continue;
                }

                $items[] = [
                    'inventory_reorder_rule_id' => $rule->id,
                    'product_id' => $rule->product_id,
                    'product_variant_id' => $rule->product_variant_id,
                    'description' => __('operations.reorder.requisition_line_description', [
                        'warehouse' => $rule->warehouse?->name ?? '',
                    ]),
                    'quantity' => $row['suggested_quantity'],
                    'estimated_unit_cost' => $row['planning_unit_cost'],
                ];
            }

            if ($items === []) {
                throw new RuntimeException(__('operations.reorder.errors.no_replenishment'));
            }

            $warehouse = $rules->first()->warehouse;

            return $this->requisitions->create([
                'warehouse_id' => $warehouse?->id,
                'request_date' => now()->toDateString(),
                'needed_by' => $neededBy,
                'purpose' => __('operations.reorder.requisition_purpose', [
                    'warehouse' => $warehouse?->name ?? '',
                ]),
                'items' => $items,
            ], $userId);
        });
    }

    public function pipelineQuantity(InventoryReorderRule $rule): string
    {
        $prQuantity = PurchaseRequisitionItem::query()
            ->where('inventory_reorder_rule_id', $rule->id)
            ->whereHas('requisition', function ($query) use ($rule): void {
                $query->where('warehouse_id', $rule->warehouse_id)
                    ->whereIn('status', ['draft', 'submitted', 'approved'])
                    ->whereDoesntHave('purchaseOrders');
            })
            ->sum('quantity');

        $poItems = PurchaseOrderItem::query()
            ->withSum('goodsReceiptItems as received_quantity', 'quantity')
            ->where('product_id', $rule->product_id)
            ->when(
                $rule->product_variant_id === null,
                fn ($query) => $query->whereNull('product_variant_id'),
                fn ($query) => $query->where('product_variant_id', $rule->product_variant_id),
            )
            ->whereHas('purchaseOrder', function ($query) use ($rule): void {
                $query->whereIn('status', ['ordered', 'partially_received'])
                    ->whereHas('requisition', function ($requisition) use ($rule): void {
                        $requisition->where('warehouse_id', $rule->warehouse_id)
                            ->whereHas('items', fn ($items) => $items->where('inventory_reorder_rule_id', $rule->id));
                    });
            })
            ->get();

        $poQuantity = '0.0000';

        foreach ($poItems as $item) {
            $remaining = Decimal::sub(
                (string) $item->quantity,
                (string) ($item->received_quantity ?? '0'),
            );

            if (Decimal::gt($remaining, '0')) {
                $poQuantity = Decimal::add($poQuantity, $remaining);
            }
        }

        return Decimal::add((string) $prQuantity, $poQuantity);
    }

    private function planningCost(InventoryReorderRule $rule, string $averageUnitCost): string
    {
        if (Decimal::gt($averageUnitCost, '0')) {
            return $averageUnitCost;
        }

        $query = StockMovement::query()
            ->where('warehouse_id', $rule->warehouse_id)
            ->where('product_id', $rule->product_id)
            ->whereNotNull('unit_cost')
            ->where('unit_cost', '>', 0);

        $rule->product_variant_id === null
            ? $query->whereNull('product_variant_id')
            : $query->where('product_variant_id', $rule->product_variant_id);

        $cost = $query
            ->latest('occurred_at')
            ->latest('id')
            ->value('unit_cost');

        return Decimal::normalize((string) ($cost ?? '0'));
    }
}
