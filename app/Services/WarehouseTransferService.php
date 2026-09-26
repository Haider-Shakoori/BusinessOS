<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\ProductType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WarehouseTransferService
{
    public function __construct(private readonly DocumentNumberService $numbers)
    {
        //
    }

    public function create(
        Warehouse $source,
        Warehouse $destination,
        Product $product,
        float $quantity,
        ?string $note = null,
    ): WarehouseTransfer {
        if ($source->is($destination)) {
            throw new RuntimeException(__('operations.transfers.errors.same_warehouse'));
        }

        if ($product->type !== ProductType::Product) {
            throw new RuntimeException(__('operations.transfers.errors.physical_product_only'));
        }

        if ($quantity <= 0) {
            throw new RuntimeException(__('operations.transfers.errors.invalid_quantity'));
        }

        return DB::transaction(function () use ($source, $destination, $product, $quantity, $note): WarehouseTransfer {
            $transfer = WarehouseTransfer::create([
                'source_warehouse_id' => $source->id,
                'destination_warehouse_id' => $destination->id,
                'number' => $this->numbers->next(DocumentType::WarehouseTransfer),
                'status' => 'draft',
                'transfer_date' => now()->toDateString(),
                'note' => $note,
            ]);

            $transfer->items()->create([
                'product_id' => $product->id,
                'quantity' => round($quantity, 4),
            ]);

            return $transfer->load(['sourceWarehouse', 'destinationWarehouse', 'items.product']);
        });
    }

    public function dispatch(WarehouseTransfer $transfer): WarehouseTransfer
    {
        return DB::transaction(function () use ($transfer): WarehouseTransfer {
            $locked = WarehouseTransfer::query()
                ->with('items.product')
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            if ($locked->status !== 'draft') {
                throw new RuntimeException(__('operations.transfers.errors.not_draft'));
            }

            foreach ($locked->items as $item) {
                $available = (float) StockMovement::query()
                    ->where('warehouse_id', $locked->source_warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->sum('quantity');

                if ($available + 0.00001 < (float) $item->quantity) {
                    throw new RuntimeException(__('operations.transfers.errors.insufficient_stock', [
                        'product' => $item->product?->name ?? (string) $item->product_id,
                        'available' => number_format($available, 4, '.', ''),
                    ]));
                }

                $unitCost = StockMovement::query()
                    ->where('warehouse_id', $locked->source_warehouse_id)
                    ->where('product_id', $item->product_id)
                    ->whereNotNull('unit_cost')
                    ->where('unit_cost', '>', 0)
                    ->latest('occurred_at')
                    ->latest('id')
                    ->value('unit_cost');

                $item->update(['unit_cost' => $unitCost]);

                StockMovement::create([
                    'warehouse_id' => $locked->source_warehouse_id,
                    'product_id' => $item->product_id,
                    'type' => 'transfer_out',
                    'quantity' => -(float) $item->quantity,
                    'unit_cost' => $unitCost,
                    'reference_type' => WarehouseTransfer::class,
                    'reference_id' => $locked->id,
                    'note' => $locked->number,
                    'occurred_at' => now(),
                ]);
            }

            $locked->update([
                'status' => 'in_transit',
                'dispatched_at' => now(),
            ]);

            return $locked->refresh();
        });
    }

    public function receive(WarehouseTransfer $transfer): WarehouseTransfer
    {
        return DB::transaction(function () use ($transfer): WarehouseTransfer {
            $locked = WarehouseTransfer::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            if ($locked->status !== 'in_transit') {
                throw new RuntimeException(__('operations.transfers.errors.not_in_transit'));
            }

            foreach ($locked->items as $item) {
                StockMovement::create([
                    'warehouse_id' => $locked->destination_warehouse_id,
                    'product_id' => $item->product_id,
                    'type' => 'transfer_in',
                    'quantity' => $item->quantity,
                    'unit_cost' => $item->unit_cost,
                    'reference_type' => WarehouseTransfer::class,
                    'reference_id' => $locked->id,
                    'note' => $locked->number,
                    'occurred_at' => now(),
                ]);
            }

            $locked->update([
                'status' => 'received',
                'received_at' => now(),
            ]);

            return $locked->refresh();
        });
    }
}
