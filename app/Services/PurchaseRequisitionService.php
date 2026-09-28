<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\Product;
use App\Models\PurchaseRequisition;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PurchaseRequisitionService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ProductVariantService $variants,
    ) {
        //
    }

    public function create(array $data, int $userId): PurchaseRequisition
    {
        return DB::transaction(function () use ($data, $userId): PurchaseRequisition {
            $requisition = PurchaseRequisition::create([
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'number' => $this->numbers->next(DocumentType::PurchaseRequisition),
                'status' => 'draft',
                'request_date' => $data['request_date'],
                'needed_by' => $data['needed_by'] ?? null,
                'purpose' => $data['purpose'] ?? null,
                'requested_by' => $userId,
                'estimated_total' => '0.0000',
            ]);

            $total = '0.0000';

            foreach ($data['items'] as $index => $item) {
                $product = Product::query()->findOrFail($item['product_id']);

                try {
                    $variant = $this->variants->resolve(
                        $product,
                        isset($item['product_variant_id']) ? (int) $item['product_variant_id'] : null,
                    );
                } catch (RuntimeException $exception) {
                    throw ValidationException::withMessages([
                        "items.{$index}.product_variant_id" => $exception->getMessage(),
                    ]);
                }

                $lineTotal = Decimal::round(Decimal::mul(
                    (string) $item['quantity'],
                    (string) $item['estimated_unit_cost'],
                ));

                $requisition->items()->create([
                    'inventory_reorder_rule_id' => $item['inventory_reorder_rule_id'] ?? null,
                    'product_id' => $product->id,
                    'product_variant_id' => $variant?->id,
                    'description' => $item['description'] ?? null,
                    'quantity' => $item['quantity'],
                    'estimated_unit_cost' => $item['estimated_unit_cost'],
                    'line_total' => $lineTotal,
                ]);

                $total = Decimal::add($total, $lineTotal);
            }

            $requisition->update(['estimated_total' => $total]);

            return $requisition->load(['warehouse', 'items.product', 'items.variant', 'items.reorderRule', 'requester']);
        });
    }

    public function submit(PurchaseRequisition $requisition): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition): PurchaseRequisition {
            $locked = PurchaseRequisition::query()->lockForUpdate()->findOrFail($requisition->id);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Only draft purchase requisitions can be submitted.',
                ]);
            }

            if (! $locked->items()->exists()) {
                throw ValidationException::withMessages([
                    'items' => 'A purchase requisition requires at least one line.',
                ]);
            }

            $locked->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'approved_by' => null,
                'approved_at' => null,
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            return $locked->refresh();
        });
    }

    public function approve(PurchaseRequisition $requisition, int $userId): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition, $userId): PurchaseRequisition {
            $locked = PurchaseRequisition::query()->lockForUpdate()->findOrFail($requisition->id);

            if ($locked->status !== 'submitted') {
                throw ValidationException::withMessages([
                    'status' => 'Only submitted purchase requisitions can be approved.',
                ]);
            }

            $locked->update([
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => now(),
                'rejected_by' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
            ]);

            return $locked->refresh();
        });
    }

    public function reject(PurchaseRequisition $requisition, int $userId, string $reason): PurchaseRequisition
    {
        return DB::transaction(function () use ($requisition, $userId, $reason): PurchaseRequisition {
            $locked = PurchaseRequisition::query()->lockForUpdate()->findOrFail($requisition->id);

            if ($locked->status !== 'submitted') {
                throw ValidationException::withMessages([
                    'status' => 'Only submitted purchase requisitions can be rejected.',
                ]);
            }

            $locked->update([
                'status' => 'rejected',
                'rejected_by' => $userId,
                'rejected_at' => now(),
                'rejection_reason' => trim($reason),
                'approved_by' => null,
                'approved_at' => null,
            ]);

            return $locked->refresh();
        });
    }
}
