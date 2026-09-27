<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AccountingPostingService $accounting,
    ) {
        //
    }

    public function receive(
        PurchaseOrder $purchaseOrder,
        int $warehouseId,
        array $submittedItems,
        int $userId,
        string $receiptDate,
        ?string $notes = null,
    ): GoodsReceipt {
        return DB::transaction(function () use ($purchaseOrder, $warehouseId, $submittedItems, $userId, $receiptDate, $notes): GoodsReceipt {
            $order = PurchaseOrder::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->id);

            if (! in_array($order->status, ['draft', 'ordered', 'partially_received'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'This purchase order is not open for receiving.',
                ]);
            }

            $remaining = $this->remainingQuantities($order);
            $submitted = collect($submittedItems);

            if ($submitted->pluck('purchase_order_item_id')->count() !== $submitted->pluck('purchase_order_item_id')->unique()->count()) {
                throw ValidationException::withMessages([
                    'items' => 'A purchase order line may only appear once in a goods receipt.',
                ]);
            }

            if ($submitted->isEmpty()) {
                $requested = $remaining
                    ->filter(fn (string $quantity): bool => Decimal::gt($quantity, '0'))
                    ->map(fn (string $quantity): string => $quantity);
            } else {
                $requested = $submitted
                    ->mapWithKeys(fn (array $item): array => [(int) $item['purchase_order_item_id'] => (string) $item['quantity']])
                    ->filter(fn (string $quantity): bool => Decimal::gt($quantity, '0'));
            }

            if ($requested->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Enter a receipt quantity greater than zero for at least one purchase order line.',
                ]);
            }

            $orderItems = $order->items->keyBy('id');

            foreach ($requested as $itemId => $quantity) {
                $item = $orderItems->get($itemId);

                if ($item === null) {
                    throw ValidationException::withMessages([
                        'items' => 'Every receipt line must belong to this purchase order.',
                    ]);
                }

                if (! Decimal::gt($quantity, '0')) {
                    throw ValidationException::withMessages([
                        'items' => 'Receipt quantities must be greater than zero.',
                    ]);
                }

                $remainingQuantity = $remaining->get($itemId, '0.0000');

                if (Decimal::gt($quantity, $remainingQuantity)) {
                    throw ValidationException::withMessages([
                        'items' => 'A receipt quantity cannot exceed the remaining purchase order quantity.',
                    ]);
                }
            }

            $receipt = GoodsReceipt::create([
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouseId,
                'number' => $this->numbers->next(DocumentType::GoodsReceipt),
                'status' => 'posted',
                'receipt_date' => $receiptDate,
                'total' => '0.0000',
                'notes' => $notes,
                'received_by' => $userId,
                'posted_at' => now(),
            ]);

            $total = '0.0000';

            foreach ($requested as $itemId => $quantity) {
                $item = $orderItems->get($itemId);
                $lineTotal = Decimal::round(Decimal::mul($quantity, (string) $item->unit_cost));

                $receipt->items()->create([
                    'purchase_order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $quantity,
                    'unit_cost' => $item->unit_cost,
                    'line_total' => $lineTotal,
                ]);

                StockMovement::create([
                    'warehouse_id' => $warehouseId,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'type' => 'purchase',
                    'quantity' => $quantity,
                    'unit_cost' => $item->unit_cost,
                    'reference_type' => GoodsReceipt::class,
                    'reference_id' => $receipt->id,
                    'note' => $receipt->number.' · '.$order->number,
                    'occurred_at' => $receiptDate,
                ]);

                $total = Decimal::add($total, $lineTotal);
            }

            $receipt->update(['total' => $total]);

            $remainingAfter = $this->remainingQuantities($order->refresh()->load('items'));
            $complete = $remainingAfter->every(fn (string $quantity): bool => ! Decimal::gt($quantity, '0'));

            $order->update([
                'status' => $complete ? 'received' : 'partially_received',
            ]);

            $this->accounting->postGoodsReceipt($receipt->refresh());

            return $receipt->load(['purchaseOrder', 'warehouse', 'receiver', 'items.product', 'items.variant']);
        });
    }

    /**
     * @return Collection<int,string>
     */
    public function remainingQuantities(PurchaseOrder $order): Collection
    {
        $received = GoodsReceiptItem::query()
            ->selectRaw('purchase_order_item_id, SUM(quantity) as received_quantity')
            ->whereIn('purchase_order_item_id', $order->items->pluck('id'))
            ->groupBy('purchase_order_item_id')
            ->pluck('received_quantity', 'purchase_order_item_id');

        return $order->items->mapWithKeys(function ($item) use ($received): array {
            $receivedQuantity = Decimal::normalize((string) ($received->get($item->id) ?? '0'));

            return [
                $item->id => Decimal::sub((string) $item->quantity, $receivedQuantity),
            ];
        });
    }
}
