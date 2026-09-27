<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\SupplierQuotation;
use App\Models\Warehouse;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderWorkflowService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AccountingPostingService $accounting,
    ) {
        //
    }

    public function convertSelectedQuotation(SupplierQuotation $quotation, array $data, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($quotation, $data, $userId): PurchaseOrder {
            $locked = SupplierQuotation::query()
                ->with(['rfq', 'items.requisitionItem'])
                ->lockForUpdate()
                ->findOrFail($quotation->id);

            if ($locked->status !== 'selected' || $locked->rfq?->status !== 'awarded') {
                throw ValidationException::withMessages([
                    'quotation' => 'Only the selected quotation from an awarded RFQ can become a purchase order.',
                ]);
            }

            $existing = PurchaseOrder::query()
                ->where('source_supplier_quotation_id', $locked->id)
                ->first();

            if ($existing !== null) {
                return $existing->load(['supplier', 'items.product', 'items.variant']);
            }

            $order = PurchaseOrder::create([
                'supplier_id' => $locked->supplier_id,
                'source_supplier_quotation_id' => $locked->id,
                'number' => $this->numbers->next(DocumentType::PurchaseOrder),
                'status' => 'issued',
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'subtotal' => $locked->subtotal,
                'total' => $locked->total,
                'notes' => $data['notes'] ?? null,
                'issued_by' => $userId,
                'issued_at' => now(),
            ]);

            foreach ($locked->items as $quoteItem) {
                $order->items()->create([
                    'product_id' => $quoteItem->product_id,
                    'product_variant_id' => $quoteItem->product_variant_id,
                    'description' => $quoteItem->requisitionItem?->description,
                    'quantity' => $quoteItem->quantity,
                    'received_quantity' => '0.0000',
                    'unit_cost' => $quoteItem->unit_cost,
                    'line_total' => $quoteItem->line_total,
                ]);
            }

            return $order->load(['supplier', 'items.product', 'items.variant', 'sourceQuotation']);
        });
    }

    public function receive(
        PurchaseOrder $purchaseOrder,
        Warehouse $warehouse,
        string $receiptDate,
        array $lines,
        int $userId,
        ?string $notes = null,
    ): GoodsReceipt {
        return DB::transaction(function () use ($purchaseOrder, $warehouse, $receiptDate, $lines, $userId, $notes): GoodsReceipt {
            $order = PurchaseOrder::query()->with('items')->lockForUpdate()->findOrFail($purchaseOrder->id);

            if (in_array($order->status, ['received', 'cancelled'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'This purchase order cannot accept another goods receipt.',
                ]);
            }

            $submitted = collect($lines)->keyBy(fn (array $line): int => (int) $line['purchase_order_item_id']);

            if ($submitted->isEmpty()) {
                throw ValidationException::withMessages(['items' => 'At least one receipt line is required.']);
            }

            $receipt = GoodsReceipt::create([
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouse->id,
                'number' => $this->numbers->next(DocumentType::GoodsReceipt),
                'receipt_date' => $receiptDate,
                'status' => 'posted',
                'total' => '0.0000',
                'notes' => $notes,
                'received_by' => $userId,
            ]);

            $total = '0.0000';

            foreach ($submitted as $purchaseOrderItemId => $line) {
                $item = $order->items->firstWhere('id', $purchaseOrderItemId);

                if ($item === null) {
                    throw ValidationException::withMessages([
                        'items' => 'Every received line must belong to the selected purchase order.',
                    ]);
                }

                $quantity = Decimal::normalize((string) $line['quantity']);

                if (! Decimal::gt($quantity, '0')) {
                    throw ValidationException::withMessages(['items' => 'Received quantity must be greater than zero.']);
                }

                $remaining = Decimal::sub((string) $item->quantity, (string) $item->received_quantity);

                if (Decimal::gt($quantity, $remaining)) {
                    throw ValidationException::withMessages([
                        'items' => 'Received quantity cannot exceed the remaining purchase order quantity.',
                    ]);
                }

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
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $item->product_id,
                    'product_variant_id' => $item->product_variant_id,
                    'type' => 'purchase',
                    'quantity' => $quantity,
                    'unit_cost' => $item->unit_cost,
                    'reference_type' => GoodsReceipt::class,
                    'reference_id' => $receipt->id,
                    'note' => $receipt->number.' / '.$order->number,
                    'occurred_at' => $receiptDate.' 12:00:00',
                ]);

                $item->update([
                    'received_quantity' => Decimal::add((string) $item->received_quantity, $quantity),
                ]);

                $total = Decimal::add($total, $lineTotal);
            }

            $receipt->update(['total' => $total]);
            $order->load('items');

            $fullyReceived = $order->items->every(
                fn ($item): bool => Decimal::eq((string) $item->received_quantity, (string) $item->quantity),
            );

            $order->update(['status' => $fullyReceived ? 'received' : 'partially_received']);

            if ($fullyReceived) {
                $this->accounting->postPurchaseReceipt($order->refresh(), $receiptDate);
            }

            return $receipt->load(['warehouse', 'items.product', 'items.variant', 'receiver']);
        });
    }

    public function receiveAllRemaining(
        PurchaseOrder $purchaseOrder,
        Warehouse $warehouse,
        string $receiptDate,
        int $userId,
        ?string $notes = null,
    ): ?GoodsReceipt {
        $order = PurchaseOrder::query()->with('items')->findOrFail($purchaseOrder->id);

        if ($order->status === 'received') {
            $this->accounting->postPurchaseReceipt($order, $receiptDate);

            return null;
        }

        $lines = $order->items
            ->map(function ($item): array {
                return [
                    'purchase_order_item_id' => $item->id,
                    'quantity' => Decimal::sub((string) $item->quantity, (string) $item->received_quantity),
                ];
            })
            ->filter(fn (array $line): bool => Decimal::gt($line['quantity'], '0'))
            ->values()
            ->all();

        return $this->receive($order, $warehouse, $receiptDate, $lines, $userId, $notes);
    }
}
