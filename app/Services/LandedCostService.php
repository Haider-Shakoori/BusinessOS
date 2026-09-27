<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\LandedCost;
use App\Models\LandedCostAllocation;
use App\Models\StockMovement;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LandedCostService
{
    private const CATEGORIES = [
        'freight',
        'customs',
        'insurance',
        'handling',
        'transport',
        'other',
    ];

    private const METHODS = ['value', 'quantity', 'manual'];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AccountingPostingService $accounting,
    ) {
        //
    }

    public function create(
        GoodsReceipt $goodsReceipt,
        array $data,
        int $userId,
    ): LandedCost {
        return DB::transaction(function () use ($goodsReceipt, $data, $userId): LandedCost {
            $receipt = GoodsReceipt::query()
                ->with(['items', 'purchaseOrder'])
                ->lockForUpdate()
                ->findOrFail($goodsReceipt->id);

            if ($receipt->status !== 'posted' || $receipt->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'goods_receipt_id' => 'Landed costs require a posted goods receipt with received items.',
                ]);
            }

            $method = (string) $data['allocation_method'];

            if (! in_array($method, self::METHODS, true)) {
                throw ValidationException::withMessages([
                    'allocation_method' => 'Unsupported landed cost allocation method.',
                ]);
            }

            $charges = collect($data['charges'] ?? [])
                ->filter(fn (array $charge): bool => Decimal::gt((string) ($charge['amount'] ?? '0'), '0'))
                ->values();

            if ($charges->isEmpty()) {
                throw ValidationException::withMessages([
                    'charges' => 'At least one landed cost charge greater than zero is required.',
                ]);
            }

            $total = '0.0000';

            foreach ($charges as $charge) {
                if (! in_array((string) $charge['category'], self::CATEGORIES, true)) {
                    throw ValidationException::withMessages([
                        'charges' => 'Unsupported landed cost charge category.',
                    ]);
                }

                $total = Decimal::add($total, Decimal::normalize((string) $charge['amount']));
            }

            $allocationRows = $this->calculateAllocations(
                $receipt->items->sortBy('id')->values(),
                $method,
                $total,
                collect($data['manual_allocations'] ?? []),
            );

            $landedCost = LandedCost::create([
                'goods_receipt_id' => $receipt->id,
                'number' => $this->numbers->next(DocumentType::LandedCost),
                'status' => 'draft',
                'cost_date' => $data['cost_date'],
                'allocation_method' => $method,
                'total' => $total,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($charges as $charge) {
                $landedCost->charges()->create([
                    'category' => $charge['category'],
                    'description' => $charge['description'] ?? null,
                    'amount' => Decimal::normalize((string) $charge['amount']),
                ]);
            }

            foreach ($allocationRows as $row) {
                $landedCost->allocations()->create($row);
            }

            return $landedCost->load([
                'goodsReceipt.purchaseOrder',
                'goodsReceipt.items.product',
                'goodsReceipt.items.variant',
                'charges',
                'allocations.goodsReceiptItem.product',
                'creator',
            ]);
        });
    }

    public function post(LandedCost $landedCost, int $userId): LandedCost
    {
        return DB::transaction(function () use ($landedCost, $userId): LandedCost {
            $cost = LandedCost::query()
                ->with([
                    'goodsReceipt.items',
                    'allocations.goodsReceiptItem',
                    'charges',
                ])
                ->lockForUpdate()
                ->findOrFail($landedCost->id);

            if ($cost->status !== 'draft') {
                throw ValidationException::withMessages([
                    'landed_cost' => 'Only draft landed cost documents can be posted.',
                ]);
            }

            $receipt = $cost->goodsReceipt;
            $this->ensureStockMovementLinks($receipt);
            $receipt->load('items.stockMovement');
            $this->assertNoOutboundMovement($receipt);

            $cost->forceFill([
                'status' => 'posted',
                'posted_by' => $userId,
                'posted_at' => now(),
            ])->save();

            $this->recalculateReceiptValuation($receipt);

            foreach ($cost->allocations as $allocation) {
                $receiptItem = GoodsReceiptItem::query()
                    ->with('stockMovement')
                    ->findOrFail($allocation->goods_receipt_item_id);

                $allocation->forceFill([
                    'final_unit_cost' => $receiptItem->stockMovement?->unit_cost ?? $allocation->final_unit_cost,
                ])->save();
            }

            $this->accounting->postLandedCost($cost->refresh());

            return $cost->refresh()->load([
                'goodsReceipt.purchaseOrder',
                'goodsReceipt.items.product',
                'goodsReceipt.items.variant',
                'charges',
                'allocations.goodsReceiptItem.product',
                'poster',
            ]);
        });
    }

    public function reverse(
        LandedCost $landedCost,
        int $userId,
        ?string $reason = null,
    ): LandedCost {
        return DB::transaction(function () use ($landedCost, $userId, $reason): LandedCost {
            $cost = LandedCost::query()
                ->with(['goodsReceipt.items.stockMovement', 'allocations'])
                ->lockForUpdate()
                ->findOrFail($landedCost->id);

            if ($cost->status !== 'posted' || $cost->reversed_at !== null) {
                throw ValidationException::withMessages([
                    'landed_cost' => 'Only an active posted landed cost document can be reversed.',
                ]);
            }

            $this->assertNoOutboundMovement($cost->goodsReceipt);

            $cost->forceFill([
                'status' => 'reversed',
                'reversed_by' => $userId,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ])->save();

            $this->accounting->reverseLandedCost(
                $cost,
                $reason ?: 'Landed cost reversed',
            );
            $this->recalculateReceiptValuation($cost->goodsReceipt);

            return $cost->refresh();
        });
    }

    public function recalculateReceiptValuation(GoodsReceipt $goodsReceipt): void
    {
        $receipt = GoodsReceipt::query()
            ->with('items.stockMovement')
            ->findOrFail($goodsReceipt->id);

        $this->ensureStockMovementLinks($receipt);
        $receipt->load('items.stockMovement');

        foreach ($receipt->items as $item) {
            $allocated = Decimal::normalize((string) LandedCostAllocation::query()
                ->join('landed_costs', 'landed_costs.id', '=', 'landed_cost_allocations.landed_cost_id')
                ->where('landed_cost_allocations.goods_receipt_item_id', $item->id)
                ->where('landed_costs.status', 'posted')
                ->sum('landed_cost_allocations.allocated_amount'));

            $effectiveValue = Decimal::add((string) $item->line_total, $allocated);
            $effectiveUnitCost = Decimal::mulDiv(
                $effectiveValue,
                '1',
                (string) $item->quantity,
            );

            if ($item->stockMovement !== null) {
                $item->stockMovement->forceFill([
                    'unit_cost' => $effectiveUnitCost,
                ])->save();
            }

        }
    }

    /**
     * @param  Collection<int, GoodsReceiptItem>  $items
     * @param  Collection<int, array<string, mixed>>  $manualAllocations
     * @return list<array<string, string|int>>
     */
    private function calculateAllocations(
        Collection $items,
        string $method,
        string $total,
        Collection $manualAllocations,
    ): array {
        if ($method === 'manual') {
            return $this->manualAllocations($items, $manualAllocations, $total);
        }

        $basis = $items->mapWithKeys(fn (GoodsReceiptItem $item): array => [
            $item->id => $method === 'value'
                ? Decimal::normalize((string) $item->line_total)
                : Decimal::normalize((string) $item->quantity),
        ]);

        $basisTotal = $basis->reduce(
            fn (string $carry, string $amount): string => Decimal::add($carry, $amount),
            '0.0000',
        );

        if (! Decimal::gt($basisTotal, '0')) {
            throw ValidationException::withMessages([
                'allocation_method' => 'The selected allocation basis has no positive value.',
            ]);
        }

        $allocated = '0.0000';
        $rows = [];
        $lastIndex = $items->count() - 1;

        foreach ($items as $index => $item) {
            $amount = $index === $lastIndex
                ? Decimal::sub($total, $allocated)
                : Decimal::mulDiv($total, $basis->get($item->id, '0.0000'), $basisTotal);

            $allocated = Decimal::add($allocated, $amount);
            $increment = Decimal::mulDiv($amount, '1', (string) $item->quantity);

            $rows[] = [
                'goods_receipt_item_id' => $item->id,
                'basis_amount' => $basis->get($item->id, '0.0000'),
                'allocated_amount' => $amount,
                'unit_cost_increment' => $increment,
                'final_unit_cost' => Decimal::add((string) $item->unit_cost, $increment),
            ];
        }

        return $rows;
    }

    /**
     * @param  Collection<int, GoodsReceiptItem>  $items
     * @param  Collection<int, array<string, mixed>>  $manualAllocations
     * @return list<array<string, string|int>>
     */
    private function manualAllocations(
        Collection $items,
        Collection $manualAllocations,
        string $total,
    ): array {
        $inputs = $manualAllocations->mapWithKeys(
            fn (array $row): array => [(int) $row['goods_receipt_item_id'] => Decimal::normalize((string) $row['amount'])]
        );

        $sum = '0.0000';
        $rows = [];

        foreach ($items as $item) {
            if (! $inputs->has($item->id)) {
                throw ValidationException::withMessages([
                    'manual_allocations' => 'Manual allocation requires an amount for every goods receipt line.',
                ]);
            }

            $amount = $inputs->get($item->id);

            if (Decimal::lt($amount, '0')) {
                throw ValidationException::withMessages([
                    'manual_allocations' => 'Manual landed cost allocations cannot be negative.',
                ]);
            }

            $sum = Decimal::add($sum, $amount);
            $increment = Decimal::mulDiv($amount, '1', (string) $item->quantity);

            $rows[] = [
                'goods_receipt_item_id' => $item->id,
                'basis_amount' => '0.0000',
                'allocated_amount' => $amount,
                'unit_cost_increment' => $increment,
                'final_unit_cost' => Decimal::add((string) $item->unit_cost, $increment),
            ];
        }

        if (! Decimal::eq($sum, $total)) {
            throw ValidationException::withMessages([
                'manual_allocations' => 'Manual allocations must exactly equal the landed cost charge total.',
            ]);
        }

        return $rows;
    }

    private function ensureStockMovementLinks(GoodsReceipt $receipt): void
    {
        $receipt->loadMissing('items');
        $unlinked = $receipt->items->whereNull('stock_movement_id');

        if ($unlinked->isEmpty()) {
            return;
        }

        $movements = StockMovement::query()
            ->where('reference_type', GoodsReceipt::class)
            ->where('reference_id', $receipt->id)
            ->where('warehouse_id', $receipt->warehouse_id)
            ->where('type', 'purchase')
            ->orderBy('id')
            ->get();

        $usedMovementIds = $receipt->items
            ->pluck('stock_movement_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($unlinked->sortBy('id') as $item) {
            $movement = $movements
                ->where('product_id', $item->product_id)
                ->filter(fn (StockMovement $candidate): bool =>
                    (int) ($candidate->product_variant_id ?? 0) === (int) ($item->product_variant_id ?? 0)
                    && ! in_array($candidate->id, $usedMovementIds, true)
                )
                ->first();

            if ($movement === null) {
                throw ValidationException::withMessages([
                    'goods_receipt_id' => 'The goods receipt valuation movement could not be resolved safely.',
                ]);
            }

            $item->forceFill(['stock_movement_id' => $movement->id])->save();
            $usedMovementIds[] = $movement->id;
        }
    }

    private function assertNoOutboundMovement(GoodsReceipt $receipt): void
    {
        $receipt->loadMissing('items.stockMovement');

        foreach ($receipt->items as $item) {
            $movement = $item->stockMovement;

            if ($movement === null) {
                throw ValidationException::withMessages([
                    'goods_receipt_id' => 'The goods receipt line is missing its stock valuation movement.',
                ]);
            }

            $query = StockMovement::query()
                ->where('warehouse_id', $receipt->warehouse_id)
                ->where('product_id', $item->product_id)
                ->where('id', '>', $movement->id)
                ->where('quantity', '<', 0);

            $item->product_variant_id === null
                ? $query->whereNull('product_variant_id')
                : $query->where('product_variant_id', $item->product_variant_id);

            if ($query->exists()) {
                throw ValidationException::withMessages([
                    'landed_cost' => 'Landed cost valuation cannot be changed after affected stock has moved out of the receipt warehouse.',
                ]);
            }
        }
    }
}
