<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\PurchaseOrder;
use App\Models\SupplierQuotation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderConversionService
{
    public function __construct(private readonly DocumentNumberService $numbers)
    {
        //
    }

    public function convert(SupplierQuotation $quotation, array $data, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($quotation, $data, $userId): PurchaseOrder {
            $quote = SupplierQuotation::query()
                ->with(['rfq.requisition', 'items'])
                ->lockForUpdate()
                ->findOrFail($quotation->id);

            if ($quote->status !== 'selected' || $quote->rfq?->status !== 'awarded') {
                throw ValidationException::withMessages([
                    'quotation' => 'Only the selected quotation from an awarded RFQ can become a purchase order.',
                ]);
            }

            if (PurchaseOrder::query()->where('supplier_quotation_id', $quote->id)->exists()) {
                throw ValidationException::withMessages([
                    'quotation' => 'This supplier quotation has already been converted to a purchase order.',
                ]);
            }

            $orderDate = CarbonImmutable::parse($data['order_date']);

            if ($quote->valid_until !== null && $orderDate->gt(CarbonImmutable::parse($quote->valid_until))) {
                throw ValidationException::withMessages([
                    'order_date' => 'The selected supplier quotation is expired for this purchase order date.',
                ]);
            }

            $order = PurchaseOrder::create([
                'supplier_id' => $quote->supplier_id,
                'purchase_requisition_id' => $quote->rfq?->purchase_requisition_id,
                'purchase_rfq_id' => $quote->purchase_rfq_id,
                'supplier_quotation_id' => $quote->id,
                'number' => $this->numbers->next(DocumentType::PurchaseOrder),
                'status' => 'ordered',
                'order_date' => $data['order_date'],
                'expected_date' => $data['expected_date'] ?? null,
                'subtotal' => $quote->subtotal,
                'total' => $quote->total,
                'notes' => $data['notes'] ?? $quote->notes,
                'converted_by' => $userId,
                'converted_at' => now(),
            ]);

            foreach ($quote->items as $quoteItem) {
                $order->items()->create([
                    'supplier_quotation_item_id' => $quoteItem->id,
                    'product_id' => $quoteItem->product_id,
                    'product_variant_id' => $quoteItem->product_variant_id,
                    'description' => $quoteItem->notes,
                    'quantity' => $quoteItem->quantity,
                    'unit_cost' => $quoteItem->unit_cost,
                    'line_total' => $quoteItem->line_total,
                ]);
            }

            return $order->load([
                'supplier',
                'items.product',
                'items.variant',
                'requisition',
                'rfq',
                'supplierQuotation',
                'converter',
            ]);
        });
    }
}
