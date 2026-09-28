<?php

namespace App\Services;

use App\Enums\ProductType;
use App\Models\InventoryReorderRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryReorderService
{
    public function __construct(private readonly InventoryValuationService $valuation)
    {
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

        if ($status !== null && in_array($status, ['ok', 'low', 'out_of_stock', 'inactive'], true)) {
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
        $planningCost = $this->planningCost($rule, $snapshot['average_unit_cost']);

        if (! $rule->is_active) {
            $status = 'inactive';
        } elseif (Decimal::lte($current, '0')) {
            $status = 'out_of_stock';
        } elseif (Decimal::lte($current, (string) $rule->reorder_point)) {
            $status = 'low';
        } else {
            $status = 'ok';
        }

        $suggested = '0.0000';

        if (in_array($status, ['low', 'out_of_stock'], true)) {
            $difference = Decimal::sub((string) $rule->target_stock, $current);
            $suggested = Decimal::gt($difference, '0') ? $difference : '0.0000';
        }

        return [
            'rule' => $rule,
            'current_quantity' => $current,
            'average_unit_cost' => $snapshot['average_unit_cost'],
            'planning_unit_cost' => $planningCost,
            'status' => $status,
            'suggested_quantity' => $suggested,
            'suggested_value' => Decimal::round(Decimal::mul($suggested, $planningCost)),
        ];
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
