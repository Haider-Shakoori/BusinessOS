<?php

namespace App\Services;

use App\Models\InventoryCount;
use App\Models\InventoryReservation;
use App\Models\PosRegister;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseTransferItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WarehouseLocationService
{
    public const TYPES = ['zone', 'aisle', 'rack', 'shelf', 'bin'];

    public function create(Warehouse $warehouse, array $data): WarehouseLocation
    {
        return DB::transaction(function () use ($warehouse, $data): WarehouseLocation {
            $parent = $this->resolveParent($warehouse, $data['parent_id'] ?? null);
            $makeDefault = (bool) ($data['is_default'] ?? false)
                || ! WarehouseLocation::query()->where('warehouse_id', $warehouse->id)->where('is_active', true)->exists();

            if ($makeDefault) {
                $this->clearDefault($warehouse->id);
            }

            $location = new WarehouseLocation([
                'warehouse_id' => $warehouse->id,
                'parent_id' => $parent?->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $data['type'],
                'is_active' => true,
                'is_default' => $makeDefault,
            ]);
            $location->business_id = $warehouse->business_id;
            $location->save();

            return $location;
        });
    }

    public function update(WarehouseLocation $location, array $data): WarehouseLocation
    {
        return DB::transaction(function () use ($location, $data): WarehouseLocation {
            $locked = WarehouseLocation::query()->lockForUpdate()->findOrFail($location->id);
            $parent = $this->resolveParent($locked->warehouse, $data['parent_id'] ?? null, $locked->id);
            $this->assertNoCycle($locked, $parent);

            $locked->update([
                'parent_id' => $parent?->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $data['type'],
            ]);

            return $locked->refresh();
        });
    }

    public function setDefault(WarehouseLocation $location): WarehouseLocation
    {
        return DB::transaction(function () use ($location): WarehouseLocation {
            $locked = WarehouseLocation::query()->lockForUpdate()->findOrFail($location->id);

            if (! $locked->is_active) {
                throw new RuntimeException(__('operations.locations.errors.default_inactive'));
            }

            $this->clearDefault($locked->warehouse_id, $locked->id);
            $locked->update(['is_default' => true]);

            return $locked->refresh();
        });
    }

    public function setActive(WarehouseLocation $location, bool $active): WarehouseLocation
    {
        return DB::transaction(function () use ($location, $active): WarehouseLocation {
            $locked = WarehouseLocation::query()->lockForUpdate()->findOrFail($location->id);

            if (! $active && $locked->is_default) {
                throw new RuntimeException(__('operations.locations.errors.default_deactivate'));
            }

            $locked->update(['is_active' => $active]);

            return $locked->refresh();
        });
    }

    public function delete(WarehouseLocation $location): void
    {
        DB::transaction(function () use ($location): void {
            $locked = WarehouseLocation::query()->lockForUpdate()->findOrFail($location->id);

            if ($locked->is_default) {
                throw new RuntimeException(__('operations.locations.errors.default_delete'));
            }

            if ($locked->children()->exists() || $this->isUsed($locked)) {
                throw new RuntimeException(__('operations.locations.errors.used_delete'));
            }

            $locked->delete();
        });
    }

    public function defaultForWarehouse(Warehouse $warehouse): WarehouseLocation
    {
        return DB::transaction(function () use ($warehouse): WarehouseLocation {
            $default = WarehouseLocation::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('is_default', true)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($default !== null) {
                return $default;
            }

            $existing = WarehouseLocation::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->clearDefault($warehouse->id, $existing->id);
                $existing->update(['is_default' => true]);

                return $existing->refresh();
            }

            $location = new WarehouseLocation([
                'warehouse_id' => $warehouse->id,
                'code' => $this->nextMainCode($warehouse),
                'name' => __('operations.locations.main_name'),
                'type' => 'bin',
                'is_active' => true,
                'is_default' => true,
            ]);
            $location->business_id = $warehouse->business_id;
            $location->save();

            return $location;
        });
    }

    public function forMovement(int $warehouseId, ?int $locationId = null): WarehouseLocation
    {
        $warehouse = Warehouse::withoutGlobalScope('business')->findOrFail($warehouseId);

        if ($locationId !== null) {
            $location = WarehouseLocation::withoutGlobalScope('business')->findOrFail($locationId);

            if ($location->warehouse_id !== $warehouse->id || $location->business_id !== $warehouse->business_id) {
                throw new RuntimeException(__('operations.locations.errors.warehouse_mismatch'));
            }

            if (! $location->is_active) {
                throw new RuntimeException(__('operations.locations.errors.inactive'));
            }

            return $location;
        }

        $location = WarehouseLocation::withoutGlobalScope('business')
            ->where('business_id', $warehouse->business_id)
            ->where('warehouse_id', $warehouse->id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($location !== null) {
            return $location;
        }

        $location = WarehouseLocation::withoutGlobalScope('business')
            ->where('business_id', $warehouse->business_id)
            ->where('warehouse_id', $warehouse->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        if ($location !== null) {
            WarehouseLocation::withoutGlobalScope('business')
                ->where('business_id', $warehouse->business_id)
                ->where('warehouse_id', $warehouse->id)
                ->where('is_default', true)
                ->whereKeyNot($location->id)
                ->update(['is_default' => false]);
            $location->update(['is_default' => true]);

            return $location->refresh();
        }

        $location = new WarehouseLocation([
            'warehouse_id' => $warehouse->id,
            'code' => $this->nextMainCode($warehouse),
            'name' => __('operations.locations.main_name'),
            'type' => 'bin',
            'is_active' => true,
            'is_default' => true,
        ]);
        $location->business_id = $warehouse->business_id;
        $location->save();

        return $location;
    }

    private function resolveParent(Warehouse $warehouse, mixed $parentId, ?int $exceptId = null): ?WarehouseLocation
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $parent = WarehouseLocation::query()
            ->where('warehouse_id', $warehouse->id)
            ->findOrFail((int) $parentId);

        if ($exceptId !== null && $parent->id === $exceptId) {
            throw new RuntimeException(__('operations.locations.errors.self_parent'));
        }

        return $parent;
    }

    private function assertNoCycle(WarehouseLocation $location, ?WarehouseLocation $parent): void
    {
        $cursor = $parent;
        $visited = [];

        while ($cursor !== null) {
            if ($cursor->id === $location->id || isset($visited[$cursor->id])) {
                throw new RuntimeException(__('operations.locations.errors.cycle'));
            }

            $visited[$cursor->id] = true;
            $cursor = $cursor->parent_id !== null
                ? WarehouseLocation::query()->find($cursor->parent_id)
                : null;
        }
    }

    private function clearDefault(int $warehouseId, ?int $exceptId = null): void
    {
        WarehouseLocation::query()
            ->where('warehouse_id', $warehouseId)
            ->where('is_default', true)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->update(['is_default' => false]);
    }

    private function isUsed(WarehouseLocation $location): bool
    {
        return StockMovement::query()->where('location_id', $location->id)->exists()
            || PosRegister::query()->where('location_id', $location->id)->exists()
            || InventoryReservation::query()->where('location_id', $location->id)->exists()
            || InventoryCount::query()->where('location_id', $location->id)->exists()
            || WarehouseTransferItem::query()
                ->where(function ($query) use ($location): void {
                    $query->where('source_location_id', $location->id)
                        ->orWhere('destination_location_id', $location->id);
                })
                ->exists();
    }

    private function nextMainCode(Warehouse $warehouse): string
    {
        $code = 'MAIN';
        $suffix = 2;

        while (WarehouseLocation::withTrashed()
            ->where('warehouse_id', $warehouse->id)
            ->where('code', $code)
            ->exists()) {
            $code = 'MAIN-'.$suffix;
            $suffix++;
        }

        return $code;
    }
}
