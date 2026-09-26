<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\ProductType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\InventoryReturn;
use App\Models\JournalEntry;
use App\Models\PosRegister;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PosService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly BusinessSettings $settings,
    ) {
        //
    }

    public function openShift(PosRegister $register, int $userId, float $openingCash): PosShift
    {
        return DB::transaction(function () use ($register, $userId, $openingCash): PosShift {
            $locked = PosRegister::query()->lockForUpdate()->findOrFail($register->id);

            if (! $locked->is_active) {
                throw new RuntimeException(__('pos.errors.register_inactive'));
            }

            $existing = PosShift::query()
                ->where('pos_register_id', $locked->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new RuntimeException(__('pos.errors.register_already_open'));
            }

            return PosShift::create([
                'pos_register_id' => $locked->id,
                'user_id' => $userId,
                'status' => 'open',
                'opened_at' => now(),
                'opening_cash' => round($openingCash, 4),
            ]);
        });
    }

    public function closeShift(PosShift $shift, int $userId, float $closingCash, ?string $note = null): PosShift
    {
        return DB::transaction(function () use ($shift, $userId, $closingCash, $note): PosShift {
            $locked = PosShift::query()->lockForUpdate()->findOrFail($shift->id);

            if ($locked->status !== 'open') {
                throw new RuntimeException(__('pos.errors.shift_closed'));
            }

            if ($locked->user_id !== $userId) {
                throw new RuntimeException(__('pos.errors.shift_owner_only'));
            }

            $cashSales = PosSale::query()
                ->where('pos_shift_id', $locked->id)
                ->where('status', 'completed')
                ->where('payment_method', 'cash')
                ->sum('total');

            $cashReturns = InventoryReturn::query()
                ->join('pos_sales', 'inventory_returns.source_id', '=', 'pos_sales.id')
                ->where('inventory_returns.type', 'sales')
                ->where('inventory_returns.status', 'completed')
                ->where('inventory_returns.source_type', PosSale::class)
                ->where('pos_sales.pos_shift_id', $locked->id)
                ->where('pos_sales.payment_method', 'cash')
                ->sum('inventory_returns.total');

            $expected = round((float) $locked->opening_cash + (float) $cashSales - (float) $cashReturns, 4);
            $variance = round($closingCash - $expected, 4);

            $locked->update([
                'status' => 'closed',
                'closed_at' => now(),
                'closing_cash' => round($closingCash, 4),
                'expected_cash' => $expected,
                'cash_variance' => $variance,
                'note' => $note,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * @param  list<array{product_id:int, quantity:float|int|string}>  $items
     */
    public function checkout(
        PosShift $shift,
        int $userId,
        array $items,
        string $paymentMethod,
        float $discountAmount = 0,
        float $amountTendered = 0,
        ?int $customerId = null,
    ): PosSale {
        return DB::transaction(function () use (
            $shift,
            $userId,
            $items,
            $paymentMethod,
            $discountAmount,
            $amountTendered,
            $customerId,
        ): PosSale {
            $lockedShift = PosShift::query()
                ->with('register')
                ->lockForUpdate()
                ->findOrFail($shift->id);

            if ($lockedShift->status !== 'open') {
                throw new RuntimeException(__('pos.errors.shift_closed'));
            }

            if ($lockedShift->user_id !== $userId) {
                throw new RuntimeException(__('pos.errors.shift_owner_only'));
            }

            $quantities = [];

            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $quantity = round((float) ($item['quantity'] ?? 0), 4);

                if ($productId <= 0 || $quantity <= 0) {
                    throw new RuntimeException(__('pos.errors.invalid_cart'));
                }

                $quantities[$productId] = round(($quantities[$productId] ?? 0) + $quantity, 4);
            }

            if ($quantities === []) {
                throw new RuntimeException(__('pos.errors.empty_cart'));
            }

            if ($customerId !== null) {
                Customer::query()->findOrFail($customerId);
            }

            if ($paymentMethod === 'credit' && $customerId === null) {
                throw new RuntimeException(__('pos.errors.credit_customer_required'));
            }

            $taxEnabled = (bool) $this->settings->get('general.tax_enabled', false);
            $products = Product::query()
                ->with('tax')
                ->whereIn('id', array_keys($quantities))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== count($quantities)) {
                throw new RuntimeException(__('pos.errors.invalid_cart'));
            }

            $prepared = [];
            $subtotal = 0.0;
            $taxTotal = 0.0;
            $costTotal = 0.0;

            foreach ($quantities as $productId => $quantity) {
                /** @var Product $product */
                $product = $products->get($productId);
                $unitPrice = round((float) $product->sale_price, 4);
                $lineSubtotal = round($unitPrice * $quantity, 4);
                $taxRate = $taxEnabled && $product->tax ? (float) $product->tax->rate : 0.0;
                $lineTax = round($lineSubtotal * $taxRate / 100, 4);
                $unitCost = 0.0;

                if ($product->type === ProductType::Product) {
                    $available = (float) StockMovement::query()
                        ->where('warehouse_id', $lockedShift->register->warehouse_id)
                        ->where('product_id', $product->id)
                        ->sum('quantity');

                    if ($available + 0.00001 < $quantity) {
                        throw new RuntimeException(__('pos.errors.insufficient_stock', [
                            'product' => $product->name,
                            'available' => number_format($available, 4, '.', ''),
                        ]));
                    }

                    $latestCost = StockMovement::query()
                        ->where('warehouse_id', $lockedShift->register->warehouse_id)
                        ->where('product_id', $product->id)
                        ->whereNotNull('unit_cost')
                        ->where('unit_cost', '>', 0)
                        ->latest('occurred_at')
                        ->latest('id')
                        ->value('unit_cost');

                    $unitCost = round((float) ($latestCost ?? 0), 4);
                }

                $lineCost = round($unitCost * $quantity, 4);

                $prepared[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_cost' => $unitCost,
                    'cost_total' => $lineCost,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $lineTax,
                    'line_total' => round($lineSubtotal + $lineTax, 4),
                ];

                $subtotal = round($subtotal + $lineSubtotal, 4);
                $taxTotal = round($taxTotal + $lineTax, 4);
                $costTotal = round($costTotal + $lineCost, 4);
            }

            $discount = round(max(0, $discountAmount), 4);

            if ($discount > $subtotal) {
                throw new RuntimeException(__('pos.errors.discount_too_large'));
            }

            $netSales = round($subtotal - $discount, 4);
            $total = round($netSales + $taxTotal, 4);

            if ($paymentMethod === 'cash') {
                if ($amountTendered + 0.00001 < $total) {
                    throw new RuntimeException(__('pos.errors.insufficient_tender'));
                }

                $tendered = round($amountTendered, 4);
                $change = round($tendered - $total, 4);
            } elseif ($paymentMethod === 'credit') {
                $tendered = 0.0;
                $change = 0.0;
            } else {
                $tendered = $total;
                $change = 0.0;
            }

            $sale = PosSale::create([
                'pos_register_id' => $lockedShift->pos_register_id,
                'pos_shift_id' => $lockedShift->id,
                'customer_id' => $customerId,
                'cashier_id' => $userId,
                'sale_number' => $this->numbers->next(DocumentType::PosSale),
                'status' => 'completed',
                'payment_method' => $paymentMethod,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_amount' => $taxTotal,
                'total' => $total,
                'amount_tendered' => $tendered,
                'change_due' => $change,
                'currency_code' => (string) $this->settings->get('regional.currency', 'AFN'),
                'completed_at' => now(),
            ]);

            foreach ($prepared as $line) {
                /** @var Product $product */
                $product = $line['product'];

                $sale->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'unit_cost' => $line['unit_cost'],
                    'cost_total' => $line['cost_total'],
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => $line['tax_amount'],
                    'line_total' => $line['line_total'],
                ]);

                if ($product->type === ProductType::Product) {
                    StockMovement::create([
                        'warehouse_id' => $lockedShift->register->warehouse_id,
                        'product_id' => $product->id,
                        'type' => 'sale',
                        'quantity' => -$line['quantity'],
                        'unit_cost' => $line['unit_cost'] > 0 ? $line['unit_cost'] : null,
                        'reference_type' => PosSale::class,
                        'reference_id' => $sale->id,
                        'note' => $sale->sale_number,
                        'occurred_at' => now(),
                    ]);
                }
            }

            $this->postAccounting($sale, $netSales, $taxTotal, $costTotal, false);

            return $sale->load(['items', 'register.warehouse', 'customer', 'cashier']);
        });
    }

    public function void(PosSale $sale, int $userId, string $reason): PosSale
    {
        return DB::transaction(function () use ($sale, $userId, $reason): PosSale {
            $locked = PosSale::query()
                ->with(['items.product', 'register'])
                ->lockForUpdate()
                ->findOrFail($sale->id);

            if ($locked->status === 'voided') {
                return $locked;
            }

            if (InventoryReturn::query()
                ->where('type', 'sales')
                ->where('source_type', PosSale::class)
                ->where('source_id', $locked->id)
                ->where('status', 'completed')
                ->exists()) {
                throw new RuntimeException(__('pos.errors.sale_has_returns'));
            }

            $netSales = round((float) $locked->subtotal - (float) $locked->discount_amount, 4);
            $costTotal = round((float) $locked->items->sum(fn ($item) => (float) $item->cost_total), 4);

            foreach ($locked->items as $item) {
                $product = $item->product;

                if ($product !== null && $product->type === ProductType::Product) {
                    StockMovement::create([
                        'warehouse_id' => $locked->register->warehouse_id,
                        'product_id' => $item->product_id,
                        'type' => 'adjustment',
                        'quantity' => $item->quantity,
                        'unit_cost' => (float) $item->unit_cost > 0 ? $item->unit_cost : null,
                        'reference_type' => PosSale::class,
                        'reference_id' => $locked->id,
                        'note' => 'VOID '.$locked->sale_number,
                        'occurred_at' => now(),
                    ]);
                }
            }

            $this->postAccounting($locked, $netSales, (float) $locked->tax_amount, $costTotal, true);

            $locked->update([
                'status' => 'voided',
                'voided_at' => now(),
                'voided_by' => $userId,
                'void_reason' => $reason,
            ]);

            return $locked->refresh();
        });
    }

    private function postAccounting(
        PosSale $sale,
        float $netSales,
        float $taxAmount,
        float $costTotal,
        bool $reversal,
    ): void {
        $accounts = $this->accounts();
        $paymentAccount = match ($sale->payment_method) {
            'cash' => $accounts['cash'],
            'card' => $accounts['card'],
            'mobile' => $accounts['mobile'],
            'credit' => $accounts['receivable'],
            default => $accounts['cash'],
        };

        $entry = JournalEntry::create([
            'number' => ($reversal ? 'V-' : 'J-').$sale->sale_number,
            'entry_date' => ($reversal ? now() : $sale->completed_at)->toDateString(),
            'status' => 'posted',
            'description' => ($reversal ? 'POS void ' : 'POS sale ').$sale->sale_number,
            'source_type' => $reversal ? 'pos_void' : PosSale::class,
            'source_id' => $sale->id,
        ]);

        if (! $reversal) {
            $entry->lines()->create(['account_id' => $paymentAccount->id, 'debit' => $sale->total, 'credit' => 0]);
            $entry->lines()->create(['account_id' => $accounts['sales']->id, 'debit' => 0, 'credit' => $netSales]);

            if ($taxAmount > 0) {
                $entry->lines()->create(['account_id' => $accounts['tax']->id, 'debit' => 0, 'credit' => $taxAmount]);
            }

            if ($costTotal > 0) {
                $entry->lines()->create(['account_id' => $accounts['cogs']->id, 'debit' => $costTotal, 'credit' => 0]);
                $entry->lines()->create(['account_id' => $accounts['inventory']->id, 'debit' => 0, 'credit' => $costTotal]);
            }

            return;
        }

        $entry->lines()->create(['account_id' => $accounts['sales']->id, 'debit' => $netSales, 'credit' => 0]);

        if ($taxAmount > 0) {
            $entry->lines()->create(['account_id' => $accounts['tax']->id, 'debit' => $taxAmount, 'credit' => 0]);
        }

        $entry->lines()->create(['account_id' => $paymentAccount->id, 'debit' => 0, 'credit' => $sale->total]);

        if ($costTotal > 0) {
            $entry->lines()->create(['account_id' => $accounts['inventory']->id, 'debit' => $costTotal, 'credit' => 0]);
            $entry->lines()->create(['account_id' => $accounts['cogs']->id, 'debit' => 0, 'credit' => $costTotal]);
        }
    }

    /**
     * @return array<string, Account>
     */
    private function accounts(): array
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
