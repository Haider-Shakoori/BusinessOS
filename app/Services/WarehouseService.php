<?php

namespace App\Services;

use App\Models\GoodsReceipt;
use App\Models\InventoryCount;
use App\Models\InventoryReorderRule;
use App\Models\InventoryReservation;
use App\Models\InventoryReturn;
use App\Models\PosRegister;
use App\Models\PurchaseRequisition;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WarehouseService
{
    public function create(array $data): Warehouse
    {
        return DB::transaction(function () use ($data): Warehouse {
            $makeDefault = (bool) ($data['is_default'] ?? false)
                || ! Warehouse::query()->where('is_active', true)->exists();

            if ($makeDefault) {
                $this->clearDefault();
            }

            $warehouse = Warehouse::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'is_active' => true,
                'is_default' => $makeDefault,
            ]);

            app(WarehouseLocationService::class)->defaultForWarehouse($warehouse);

            return $warehouse;
        });
    }

    public function update(Warehouse $warehouse, array $data): Warehouse
    {
        $warehouse->update(['code' => $data['code'], 'name' => $data['name']]);

        return $warehouse->refresh();
    }

    public function setDefault(Warehouse $warehouse): Warehouse
    {
        return DB::transaction(function () use ($warehouse): Warehouse {
            $locked = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);

            if (! $locked->is_active) {
                throw new RuntimeException(__('operations.warehouses.errors.default_inactive'));
            }

            $this->clearDefault($locked->id);
            $locked->update(['is_default' => true]);

            return $locked->refresh();
        });
    }

    public function setActive(Warehouse $warehouse, bool $active): Warehouse
    {
        return DB::transaction(function () use ($warehouse, $active): Warehouse {
            $locked = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);

            if (! $active && $locked->is_default) {
                throw new RuntimeException(__('operations.warehouses.errors.default_deactivate'));
            }

            $locked->update(['is_active' => $active]);

            if ($active && ! Warehouse::query()->where('is_default', true)->where('is_active', true)->exists()) {
                $this->clearDefault($locked->id);
                $locked->update(['is_default' => true]);
            }

            return $locked->refresh();
        });
    }

    public function delete(Warehouse $warehouse): void
    {
        DB::transaction(function () use ($warehouse): void {
            $locked = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);

            if ($locked->is_default) {
                throw new RuntimeException(__('operations.warehouses.errors.default_delete'));
            }

            if ($this->isUsed($locked)) {
                throw new RuntimeException(__('operations.warehouses.errors.used_delete'));
            }

            $locked->locations()->delete();
            $locked->delete();
        });
    }

    public function defaultOrCreate(): Warehouse
    {
        return DB::transaction(function (): Warehouse {
            $default = Warehouse::query()
                ->where('is_default', true)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($default !== null) {
                app(WarehouseLocationService::class)->defaultForWarehouse($default);

                return $default;
            }

            $warehouse = Warehouse::query()
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($warehouse !== null) {
                $this->clearDefault($warehouse->id);
                $warehouse->update(['is_default' => true]);
                $warehouse = $warehouse->refresh();
                app(WarehouseLocationService::class)->defaultForWarehouse($warehouse);

                return $warehouse;
            }

            $warehouse = Warehouse::create([
                'code' => $this->nextMainCode(),
                'name' => 'Main Warehouse',
                'is_active' => true,
                'is_default' => true,
            ]);
            app(WarehouseLocationService::class)->defaultForWarehouse($warehouse);

            return $warehouse;
        });
    }

    private function clearDefault(?int $exceptId = null): void
    {
        Warehouse::query()
            ->where('is_default', true)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_default' => false]);
    }

    private function isUsed(Warehouse $warehouse): bool
    {
        return $warehouse->stockMovements()->exists()
            || PosRegister::query()->where('warehouse_id', $warehouse->id)->exists()
            || GoodsReceipt::query()->where('warehouse_id', $warehouse->id)->exists()
            || InventoryCount::query()->where('warehouse_id', $warehouse->id)->exists()
            || InventoryReservation::query()->where('warehouse_id', $warehouse->id)->exists()
            || InventoryReturn::query()->where('warehouse_id', $warehouse->id)->exists()
            || InventoryReorderRule::query()->where('warehouse_id', $warehouse->id)->exists()
            || PurchaseRequisition::query()->where('warehouse_id', $warehouse->id)->exists()
            || WarehouseTransfer::query()
                ->where(function ($query) use ($warehouse): void {
                    $query->where('source_warehouse_id', $warehouse->id)
                        ->orWhere('destination_warehouse_id', $warehouse->id);
                })
                ->exists();
    }

    private function nextMainCode(): string
    {
        $code = 'MAIN';
        $suffix = 2;

        while (Warehouse::withTrashed()->where('code', $code)->exists()) {
            $code = 'MAIN-'.$suffix;
            $suffix++;
        }

        return $code;
    }
}
