<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\GoodsReceiptItem;
use App\Models\InventoryReturnItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierInvoiceService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AccountingPostingService $accounting,
    ) {
        //
    }

    public function create(PurchaseOrder $purchaseOrder, array $data, int $userId): SupplierInvoice
    {
        return DB::transaction(function () use ($purchaseOrder, $data, $userId): SupplierInvoice {
            $order = PurchaseOrder::query()
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($purchaseOrder->id);

            if (! in_array($order->status, ['partially_received', 'received'], true)) {
                throw ValidationException::withMessages([
                    'purchase_order_id' => 'Supplier invoices require a purchase order with received goods.',
                ]);
            }

            if (SupplierInvoice::query()
                ->where('supplier_id', $order->supplier_id)
                ->where('supplier_invoice_number', $data['supplier_invoice_number'])
                ->exists()) {
                throw ValidationException::withMessages([
                    'supplier_invoice_number' => 'This supplier invoice number is already recorded for the supplier.',
                ]);
            }

            $rawInputs = collect($data['items']);

            if ($rawInputs->pluck('purchase_order_item_id')->count() !== $rawInputs->pluck('purchase_order_item_id')->unique()->count()) {
                throw ValidationException::withMessages([
                    'items' => 'A purchase order line may only appear once on a supplier invoice.',
                ]);
            }

            $inputs = $rawInputs
                ->filter(fn (array $item): bool => Decimal::gt((string) $item['quantity'], '0'))
                ->mapWithKeys(fn (array $item): array => [(int) $item['purchase_order_item_id'] => $item]);

            $received = $this->receivedQuantities($order);
            $available = $this->availableQuantities($order);

            if ($inputs->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'At least one supplier invoice line is required.',
                ]);
            }

            $orderItems = $order->items->keyBy('id');
            $invoice = SupplierInvoice::create([
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'number' => $this->numbers->next(DocumentType::SupplierInvoice),
                'supplier_invoice_number' => $data['supplier_invoice_number'],
                'status' => 'draft',
                'match_status' => 'matched',
                'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'] ?? null,
                'subtotal' => '0.0000',
                'total' => '0.0000',
                'po_basis_total' => '0.0000',
                'price_variance_total' => '0.0000',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $subtotal = '0.0000';
            $poBasisTotal = '0.0000';
            $priceVarianceTotal = '0.0000';
            $hasVariance = false;

            foreach ($inputs as $itemId => $input) {
                $orderItem = $orderItems->get($itemId);

                if ($orderItem === null) {
                    throw ValidationException::withMessages([
                        'items' => 'Every supplier invoice line must belong to the selected purchase order.',
                    ]);
                }

                $quantity = Decimal::normalize((string) $input['quantity']);
                $unitCost = Decimal::normalize((string) $input['unit_cost']);

                if (! Decimal::gt($quantity, '0')) {
                    throw ValidationException::withMessages([
                        'items' => 'Supplier invoice quantities must be greater than zero.',
                    ]);
                }

                if (Decimal::gt($quantity, $available->get($orderItem->id, '0.0000'))) {
                    throw ValidationException::withMessages([
                        'items' => 'Supplier invoice quantity cannot exceed received and uninvoiced quantity.',
                    ]);
                }

                $lineTotal = Decimal::round(Decimal::mul($quantity, $unitCost));
                $poBasis = Decimal::round(Decimal::mul($quantity, (string) $orderItem->unit_cost));
                $variance = Decimal::sub($lineTotal, $poBasis);

                if (! Decimal::isZero($variance)) {
                    $hasVariance = true;
                }

                $invoice->items()->create([
                    'purchase_order_item_id' => $orderItem->id,
                    'product_id' => $orderItem->product_id,
                    'product_variant_id' => $orderItem->product_variant_id,
                    'quantity' => $quantity,
                    'received_quantity_snapshot' => $received->get($orderItem->id, '0.0000'),
                    'available_quantity_snapshot' => $available->get($orderItem->id, '0.0000'),
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'po_unit_cost' => $orderItem->unit_cost,
                    'po_basis_total' => $poBasis,
                    'price_variance' => $variance,
                ]);

                $subtotal = Decimal::add($subtotal, $lineTotal);
                $poBasisTotal = Decimal::add($poBasisTotal, $poBasis);
                $priceVarianceTotal = Decimal::add($priceVarianceTotal, $variance);
            }

            $invoice->update([
                'subtotal' => $subtotal,
                'total' => $subtotal,
                'po_basis_total' => $poBasisTotal,
                'price_variance_total' => $priceVarianceTotal,
                'match_status' => $hasVariance ? 'price_variance' : 'matched',
            ]);

            return $invoice->load([
                'purchaseOrder.supplier',
                'items.product',
                'items.variant',
                'creator',
            ]);
        });
    }

    public function submit(SupplierInvoice $supplierInvoice, int $userId): SupplierInvoice
    {
        return DB::transaction(function () use ($supplierInvoice, $userId): SupplierInvoice {
            $invoice = SupplierInvoice::query()
                ->with(['items', 'purchaseOrder.items'])
                ->lockForUpdate()
                ->findOrFail($supplierInvoice->id);

            if ($invoice->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Only draft supplier invoices can be submitted.',
                ]);
            }

            $this->assertQuantitiesStillAvailable($invoice);

            $invoice->update([
                'status' => 'submitted',
                'submitted_by' => $userId,
                'submitted_at' => now(),
            ]);

            return $invoice->refresh();
        });
    }

    public function approve(SupplierInvoice $supplierInvoice, int $userId, ?string $overrideReason = null): SupplierInvoice
    {
        return DB::transaction(function () use ($supplierInvoice, $userId, $overrideReason): SupplierInvoice {
            $invoice = SupplierInvoice::query()
                ->with(['items', 'purchaseOrder.items'])
                ->lockForUpdate()
                ->findOrFail($supplierInvoice->id);

            if ($invoice->status !== 'submitted') {
                throw ValidationException::withMessages([
                    'status' => 'Only submitted supplier invoices can be approved.',
                ]);
            }

            $this->assertQuantitiesStillAvailable($invoice);

            $updates = [
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => now(),
            ];

            if ($invoice->match_status !== 'matched') {
                if (trim((string) $overrideReason) === '') {
                    throw ValidationException::withMessages([
                        'match_override_reason' => 'A reason is required to approve a supplier invoice with a three-way match exception.',
                    ]);
                }

                $updates['match_override_by'] = $userId;
                $updates['match_override_at'] = now();
                $updates['match_override_reason'] = trim((string) $overrideReason);
            }

            $invoice->update($updates);
            $invoice = $invoice->refresh();
            $this->accounting->postSupplierInvoice($invoice);

            return $invoice;
        });
    }

    public function reject(SupplierInvoice $supplierInvoice, int $userId, string $reason): SupplierInvoice
    {
        return DB::transaction(function () use ($supplierInvoice, $userId, $reason): SupplierInvoice {
            $invoice = SupplierInvoice::query()->lockForUpdate()->findOrFail($supplierInvoice->id);

            if ($invoice->status !== 'submitted') {
                throw ValidationException::withMessages([
                    'status' => 'Only submitted supplier invoices can be rejected.',
                ]);
            }

            if (trim($reason) === '') {
                throw ValidationException::withMessages([
                    'rejection_reason' => 'A rejection reason is required.',
                ]);
            }

            $invoice->update([
                'status' => 'rejected',
                'rejected_by' => $userId,
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
            ]);

            return $invoice->refresh();
        });
    }

    /**
     * Received quantity less quantities already reserved by submitted or approved invoices.
     *
     * @return Collection<int,string>
     */
    public function availableQuantities(PurchaseOrder $order, ?SupplierInvoice $exclude = null): Collection
    {
        $order->loadMissing('items');
        $received = $this->receivedQuantities($order);

        $reservedQuery = SupplierInvoiceItem::query()
            ->selectRaw('supplier_invoice_items.purchase_order_item_id, SUM(supplier_invoice_items.quantity) as invoiced_quantity')
            ->join('supplier_invoices', 'supplier_invoices.id', '=', 'supplier_invoice_items.supplier_invoice_id')
            ->whereIn('supplier_invoice_items.purchase_order_item_id', $order->items->pluck('id'))
            ->whereIn('supplier_invoices.status', ['submitted', 'approved']);

        if ($exclude !== null) {
            $reservedQuery->where('supplier_invoices.id', '!=', $exclude->id);
        }

        $reserved = $reservedQuery
            ->groupBy('supplier_invoice_items.purchase_order_item_id')
            ->pluck('invoiced_quantity', 'supplier_invoice_items.purchase_order_item_id');

        return $order->items->mapWithKeys(function ($item) use ($received, $reserved): array {
            $receivedQuantity = $received->get($item->id, '0.0000');
            $reservedQuantity = Decimal::normalize((string) ($reserved->get($item->id) ?? '0'));

            return [
                $item->id => Decimal::min(Decimal::sub($receivedQuantity, $reservedQuantity), '0.0000'),
            ];
        });
    }

    /**
     * @return Collection<int,string>
     */
    public function receivedQuantities(PurchaseOrder $order): Collection
    {
        $order->loadMissing('items');

        $received = GoodsReceiptItem::query()
            ->selectRaw('goods_receipt_items.purchase_order_item_id, SUM(goods_receipt_items.quantity) as received_quantity')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_items.goods_receipt_id')
            ->where('goods_receipts.purchase_order_id', $order->id)
            ->where('goods_receipts.status', 'posted')
            ->groupBy('goods_receipt_items.purchase_order_item_id')
            ->pluck('received_quantity', 'goods_receipt_items.purchase_order_item_id');

        $returned = InventoryReturnItem::query()
            ->selectRaw('inventory_return_items.source_item_id, SUM(inventory_return_items.quantity) as returned_quantity')
            ->join('inventory_returns', 'inventory_returns.id', '=', 'inventory_return_items.inventory_return_id')
            ->where('inventory_returns.type', 'purchase')
            ->where('inventory_returns.status', 'completed')
            ->where('inventory_return_items.source_item_type', PurchaseOrderItem::class)
            ->whereIn('inventory_return_items.source_item_id', $order->items->pluck('id'))
            ->groupBy('inventory_return_items.source_item_id')
            ->pluck('returned_quantity', 'inventory_return_items.source_item_id');

        $legacyFallback = $received->isEmpty() && $order->status === 'received';

        return $order->items->mapWithKeys(function ($item) use ($received, $returned, $legacyFallback): array {
            $gross = $legacyFallback
                ? Decimal::normalize((string) $item->quantity)
                : Decimal::normalize((string) ($received->get($item->id) ?? '0'));
            $returnedQuantity = Decimal::normalize((string) ($returned->get($item->id) ?? '0'));

            return [
                $item->id => Decimal::min(Decimal::sub($gross, $returnedQuantity), '0.0000'),
            ];
        });
    }

    private function assertQuantitiesStillAvailable(SupplierInvoice $invoice): void
    {
        $available = $this->availableQuantities($invoice->purchaseOrder, $invoice);

        foreach ($invoice->items as $item) {
            if (Decimal::gt((string) $item->quantity, $available->get($item->purchase_order_item_id, '0.0000'))) {
                throw ValidationException::withMessages([
                    'items' => 'Supplier invoice quantity exceeds the remaining received and uninvoiced quantity.',
                ]);
            }
        }
    }
}
