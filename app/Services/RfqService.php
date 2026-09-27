<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRfq;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RfqService
{
    public function __construct(private readonly DocumentNumberService $numbers)
    {
        //
    }

    public function create(PurchaseRequisition $requisition, array $data, int $userId): PurchaseRfq
    {
        return DB::transaction(function () use ($requisition, $data, $userId): PurchaseRfq {
            $locked = PurchaseRequisition::query()->with('items')->lockForUpdate()->findOrFail($requisition->id);

            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages([
                    'purchase_requisition_id' => 'Only approved purchase requisitions can be sourced.',
                ]);
            }

            $supplierIds = collect($data['supplier_ids'])->map(fn ($id): int => (int) $id)->unique()->values();
            $suppliers = Supplier::query()->whereIn('id', $supplierIds)->where('is_active', true)->get();

            if ($suppliers->count() !== $supplierIds->count()) {
                throw ValidationException::withMessages([
                    'supplier_ids' => 'Every invited supplier must be active and belong to the current business.',
                ]);
            }

            $rfq = PurchaseRfq::create([
                'purchase_requisition_id' => $locked->id,
                'number' => $this->numbers->next(DocumentType::RequestForQuotation),
                'status' => 'draft',
                'issue_date' => $data['issue_date'],
                'response_due_date' => $data['response_due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            foreach ($supplierIds as $supplierId) {
                $rfq->supplierInvitations()->create(['supplier_id' => $supplierId]);
            }

            return $rfq->load(['requisition.items.product', 'invitedSuppliers']);
        });
    }

    public function open(PurchaseRfq $rfq): PurchaseRfq
    {
        return DB::transaction(function () use ($rfq): PurchaseRfq {
            $locked = PurchaseRfq::query()->lockForUpdate()->findOrFail($rfq->id);

            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['status' => 'Only draft RFQs can be opened.']);
            }

            if (! $locked->supplierInvitations()->exists()) {
                throw ValidationException::withMessages(['supplier_ids' => 'At least one supplier must be invited.']);
            }

            $now = now();
            $locked->supplierInvitations()->whereNull('invited_at')->update(['invited_at' => $now]);
            $locked->update(['status' => 'open', 'opened_at' => $now]);

            return $locked->refresh();
        });
    }

    public function recordQuotation(PurchaseRfq $rfq, Supplier $supplier, array $data, int $userId): SupplierQuotation
    {
        return DB::transaction(function () use ($rfq, $supplier, $data, $userId): SupplierQuotation {
            $locked = PurchaseRfq::query()
                ->with(['requisition.items', 'supplierInvitations'])
                ->lockForUpdate()
                ->findOrFail($rfq->id);

            if ($locked->status !== 'open') {
                throw ValidationException::withMessages(['status' => 'Supplier quotations can only be recorded for an open RFQ.']);
            }

            $invitation = $locked->supplierInvitations->firstWhere('supplier_id', $supplier->id);

            if ($invitation === null) {
                throw ValidationException::withMessages(['supplier_id' => 'This supplier was not invited to the RFQ.']);
            }

            if (SupplierQuotation::query()->where('purchase_rfq_id', $locked->id)->where('supplier_id', $supplier->id)->exists()) {
                throw ValidationException::withMessages(['supplier_id' => 'A quotation for this supplier is already recorded.']);
            }

            $items = collect($data['items'])->keyBy(fn (array $item): int => (int) $item['purchase_requisition_item_id']);
            $requiredIds = $locked->requisition->items->pluck('id')->sort()->values();
            $submittedIds = $items->keys()->sort()->values();

            if ($requiredIds->all() !== $submittedIds->all()) {
                throw ValidationException::withMessages(['items' => 'A supplier quotation must price every requisition line exactly once.']);
            }

            $quotation = SupplierQuotation::create([
                'purchase_rfq_id' => $locked->id,
                'supplier_id' => $supplier->id,
                'number' => $this->numbers->next(DocumentType::SupplierQuotation),
                'supplier_reference' => $data['supplier_reference'] ?? null,
                'status' => 'received',
                'quote_date' => $data['quote_date'],
                'valid_until' => $data['valid_until'] ?? null,
                'subtotal' => '0.0000',
                'total' => '0.0000',
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $userId,
            ]);

            $total = '0.0000';

            foreach ($locked->requisition->items as $reqItem) {
                $input = $items->get($reqItem->id);
                $lineTotal = Decimal::round(Decimal::mul((string) $reqItem->quantity, (string) $input['unit_cost']));

                $quotation->items()->create([
                    'purchase_requisition_item_id' => $reqItem->id,
                    'product_id' => $reqItem->product_id,
                    'product_variant_id' => $reqItem->product_variant_id,
                    'quantity' => $reqItem->quantity,
                    'unit_cost' => $input['unit_cost'],
                    'line_total' => $lineTotal,
                    'notes' => $input['notes'] ?? null,
                ]);

                $total = Decimal::add($total, $lineTotal);
            }

            $quotation->update(['subtotal' => $total, 'total' => $total]);
            $invitation->update(['responded_at' => now()]);

            return $quotation->load(['supplier', 'items.product', 'items.variant']);
        });
    }

    public function award(PurchaseRfq $rfq, SupplierQuotation $quotation, int $userId): SupplierQuotation
    {
        return DB::transaction(function () use ($rfq, $quotation, $userId): SupplierQuotation {
            $lockedRfq = PurchaseRfq::query()->lockForUpdate()->findOrFail($rfq->id);
            $lockedQuote = SupplierQuotation::query()->lockForUpdate()->findOrFail($quotation->id);

            if ($lockedRfq->status !== 'open') {
                throw ValidationException::withMessages(['status' => 'Only an open RFQ can be awarded.']);
            }

            if ($lockedQuote->purchase_rfq_id !== $lockedRfq->id || $lockedQuote->status !== 'received') {
                throw ValidationException::withMessages(['quotation' => 'The selected quotation is not an eligible response to this RFQ.']);
            }

            SupplierQuotation::query()
                ->where('purchase_rfq_id', $lockedRfq->id)
                ->where('id', '!=', $lockedQuote->id)
                ->where('status', 'received')
                ->update(['status' => 'not_selected']);

            $lockedQuote->update([
                'status' => 'selected',
                'selected_by' => $userId,
                'selected_at' => now(),
            ]);
            $lockedRfq->update([
                'status' => 'awarded',
                'awarded_at' => now(),
            ]);

            return $lockedQuote->refresh();
        });
    }

    public function comparison(PurchaseRfq $rfq): Collection
    {
        $quotes = $rfq->quotations()
            ->with(['supplier', 'items.product', 'items.variant'])
            ->whereIn('status', ['received', 'selected', 'not_selected'])
            ->orderBy('total')
            ->get();

        if ($quotes->isEmpty()) {
            return collect();
        }

        $lowest = (string) $quotes->first()->total;

        return $quotes->map(function (SupplierQuotation $quote) use ($lowest): array {
            return [
                'quotation' => $quote,
                'delta_from_lowest' => Decimal::sub((string) $quote->total, $lowest),
                'is_lowest' => Decimal::eq((string) $quote->total, $lowest),
            ];
        });
    }
}
