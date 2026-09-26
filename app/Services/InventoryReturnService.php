<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\ProductType;
use App\Models\Account;
use App\Models\InventoryReturn;
use App\Models\InventoryReturnItem;
use App\Models\JournalEntry;
use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryReturnService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AccountingPostingService $accounting,
    ) {
        //
    }

    public function salesReturn(
        PosSale $sale,
        PosSaleItem $item,
        float $quantity,
        string $reason,
        int $userId,
    ): InventoryReturn {
        return DB::transaction(function () use ($sale, $item, $quantity, $reason, $userId): InventoryReturn {
            $lockedSale = PosSale::query()
                ->with('register')
                ->lockForUpdate()
                ->findOrFail($sale->id);

            $lockedItem = PosSaleItem::query()
                ->where('pos_sale_id', $lockedSale->id)
                ->lockForUpdate()
                ->findOrFail($item->id);

            if ($lockedSale->status !== 'completed') {
                throw new RuntimeException(__('operations.returns.errors.sale_not_returnable'));
            }

            $this->assertReturnableQuantity(PosSaleItem::class, $lockedItem->id, (float) $lockedItem->quantity, $quantity);

            $lineSubtotal = round((float) $lockedItem->unit_price * $quantity, 4);
            $ratio = (float) $lockedItem->quantity > 0 ? $quantity / (float) $lockedItem->quantity : 0;
            $tax = round((float) $lockedItem->tax_amount * $ratio, 4);
            $discount = (float) $lockedSale->subtotal > 0
                ? round((float) $lockedSale->discount_amount * ($lineSubtotal / (float) $lockedSale->subtotal), 4)
                : 0.0;
            $total = round($lineSubtotal - $discount + $tax, 4);
            $unitCost = round((float) $lockedItem->unit_cost, 4);
            $costTotal = round($unitCost * $quantity, 4);

            $return = InventoryReturn::create([
                'warehouse_id' => $lockedSale->register->warehouse_id,
                'processed_by' => $userId,
                'number' => $this->numbers->next(DocumentType::InventoryReturn),
                'type' => 'sales',
                'source_type' => PosSale::class,
                'source_id' => $lockedSale->id,
                'status' => 'completed',
                'subtotal' => $lineSubtotal,
                'tax_amount' => $tax,
                'total' => $total,
                'reason' => $reason,
                'processed_at' => now(),
            ]);

            $return->items()->create([
                'product_id' => $lockedItem->product_id,
                'source_item_type' => PosSaleItem::class,
                'source_item_id' => $lockedItem->id,
                'quantity' => $quantity,
                'unit_amount' => $lockedItem->unit_price,
                'unit_cost' => $unitCost > 0 ? $unitCost : null,
                'line_subtotal' => $lineSubtotal,
                'tax_amount' => $tax,
                'discount_amount' => $discount,
                'line_total' => $total,
            ]);

            if ($lockedItem->product?->type === ProductType::Product) {
                StockMovement::create([
                    'warehouse_id' => $return->warehouse_id,
                    'product_id' => $lockedItem->product_id,
                    'type' => 'sales_return',
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost > 0 ? $unitCost : null,
                    'reference_type' => InventoryReturn::class,
                    'reference_id' => $return->id,
                    'note' => $return->number,
                    'occurred_at' => now(),
                ]);
            }

            $this->postSalesReturnAccounting(
                $return,
                $lockedSale,
                round($lineSubtotal - $discount, 4),
                $tax,
                $costTotal,
            );

            return $return->load(['warehouse', 'items.product', 'processor']);
        });
    }

    public function purchaseReturn(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderItem $item,
        Warehouse $warehouse,
        float $quantity,
        string $reason,
        int $userId,
    ): InventoryReturn {
        return DB::transaction(function () use ($purchaseOrder, $item, $warehouse, $quantity, $reason, $userId): InventoryReturn {
            $lockedOrder = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrder->id);
            $lockedItem = PurchaseOrderItem::query()
                ->where('purchase_order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->findOrFail($item->id);

            if ($lockedOrder->status !== 'received') {
                throw new RuntimeException(__('operations.returns.errors.purchase_not_returnable'));
            }

            $this->assertReturnableQuantity(PurchaseOrderItem::class, $lockedItem->id, (float) $lockedItem->quantity, $quantity);

            $available = (float) StockMovement::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $lockedItem->product_id)
                ->sum('quantity');

            if ($available + 0.00001 < $quantity) {
                throw new RuntimeException(__('operations.returns.errors.insufficient_stock', [
                    'available' => number_format($available, 4, '.', ''),
                ]));
            }

            $lineSubtotal = round((float) $lockedItem->unit_cost * $quantity, 4);

            $return = InventoryReturn::create([
                'warehouse_id' => $warehouse->id,
                'processed_by' => $userId,
                'number' => $this->numbers->next(DocumentType::InventoryReturn),
                'type' => 'purchase',
                'source_type' => PurchaseOrder::class,
                'source_id' => $lockedOrder->id,
                'status' => 'completed',
                'subtotal' => $lineSubtotal,
                'tax_amount' => 0,
                'total' => $lineSubtotal,
                'reason' => $reason,
                'processed_at' => now(),
            ]);

            $return->items()->create([
                'product_id' => $lockedItem->product_id,
                'source_item_type' => PurchaseOrderItem::class,
                'source_item_id' => $lockedItem->id,
                'quantity' => $quantity,
                'unit_amount' => $lockedItem->unit_cost,
                'unit_cost' => $lockedItem->unit_cost,
                'line_subtotal' => $lineSubtotal,
                'tax_amount' => 0,
                'discount_amount' => 0,
                'line_total' => $lineSubtotal,
            ]);

            StockMovement::create([
                'warehouse_id' => $warehouse->id,
                'product_id' => $lockedItem->product_id,
                'type' => 'purchase_return',
                'quantity' => -$quantity,
                'unit_cost' => $lockedItem->unit_cost,
                'reference_type' => InventoryReturn::class,
                'reference_id' => $return->id,
                'note' => $return->number,
                'occurred_at' => now(),
            ]);

            $this->accounting->postPurchaseReturn($return);

            return $return->load(['warehouse', 'items.product', 'processor']);
        });
    }

    private function assertReturnableQuantity(
        string $sourceItemType,
        int $sourceItemId,
        float $originalQuantity,
        float $quantity,
    ): void {
        if ($quantity <= 0) {
            throw new RuntimeException(__('operations.returns.errors.invalid_quantity'));
        }

        $alreadyReturned = (float) InventoryReturnItem::query()
            ->where('source_item_type', $sourceItemType)
            ->where('source_item_id', $sourceItemId)
            ->whereHas('inventoryReturn', fn ($query) => $query->where('status', 'completed'))
            ->sum('quantity');

        $remaining = round($originalQuantity - $alreadyReturned, 4);

        if ($quantity > $remaining + 0.00001) {
            throw new RuntimeException(__('operations.returns.errors.exceeds_original', [
                'remaining' => number_format(max(0, $remaining), 4, '.', ''),
            ]));
        }
    }

    private function postSalesReturnAccounting(
        InventoryReturn $return,
        PosSale $sale,
        float $netSales,
        float $tax,
        float $cost,
    ): void {
        $accounts = $this->posAccounts();
        $paymentAccount = match ($sale->payment_method) {
            'cash' => $accounts['cash'],
            'card' => $accounts['card'],
            'mobile' => $accounts['mobile'],
            'credit' => $accounts['receivable'],
            default => $accounts['cash'],
        };

        $entry = JournalEntry::create([
            'number' => 'J-'.$return->number,
            'entry_date' => now()->toDateString(),
            'status' => 'posted',
            'description' => 'Sales return '.$return->number.' for '.$sale->sale_number,
            'source_type' => InventoryReturn::class,
            'source_id' => $return->id,
        ]);

        $entry->lines()->create(['account_id' => $accounts['sales']->id, 'debit' => $netSales, 'credit' => 0]);

        if ($tax > 0) {
            $entry->lines()->create(['account_id' => $accounts['tax']->id, 'debit' => $tax, 'credit' => 0]);
        }

        $entry->lines()->create(['account_id' => $paymentAccount->id, 'debit' => 0, 'credit' => $return->total]);

        if ($cost > 0) {
            $entry->lines()->create(['account_id' => $accounts['inventory']->id, 'debit' => $cost, 'credit' => 0]);
            $entry->lines()->create(['account_id' => $accounts['cogs']->id, 'debit' => 0, 'credit' => $cost]);
        }
    }

    /**
     * @return array<string, Account>
     */
    private function posAccounts(): array
    {
        return [
            'cash' => Account::firstOrCreate(['code' => 'POS-CASH'], ['name' => 'POS Cash', 'type' => 'asset', 'is_active' => true]),
            'card' => Account::firstOrCreate(['code' => 'POS-CARD'], ['name' => 'POS Card Clearing', 'type' => 'asset', 'is_active' => true]),
            'mobile' => Account::firstOrCreate(['code' => 'POS-MOBILE'], ['name' => 'POS Mobile Money', 'type' => 'asset', 'is_active' => true]),
            'receivable' => Account::firstOrCreate(['code' => 'POS-AR'], ['name' => 'POS Accounts Receivable', 'type' => 'asset', 'is_active' => true]),
            'sales' => Account::firstOrCreate(['code' => 'POS-SALES'], ['name' => 'POS Sales Revenue', 'type' => 'income', 'is_active' => true]),
            'tax' => Account::firstOrCreate(['code' => 'POS-TAX'], ['name' => 'POS Tax Payable', 'type' => 'liability', 'is_active' => true]),
            'inventory' => Account::firstOrCreate(['code' => 'POS-INVENTORY'], ['name' => 'POS Inventory', 'type' => 'asset', 'is_active' => true]),
            'cogs' => Account::firstOrCreate(['code' => 'POS-COGS'], ['name' => 'POS Cost of Goods Sold', 'type' => 'expense', 'is_active' => true]),
        ];
    }
}
