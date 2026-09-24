<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\Tax;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Quotation create/update orchestration (Batch 14).
 *
 * The service owns the write path: it opens the outer transaction, allocates
 * the document number from DocumentNumberService (whose own transaction nests
 * as a savepoint) and stores the header + item snapshot rows so a failure
 * rolls back the number as well. Business ownership comes automatically from
 * BusinessContext/BelongsToBusiness; this service never accepts a business_id
 * from a request. Totals are recomputed by QuotationCalculator from the
 * sanitised lines and are never trusted from input.
 *
 * Tax snapshots: while general.tax_enabled is on, each line's tax_id is
 * re-resolved to the ACTIVE current-business Tax row and its `rate` is copied
 * into the item's tax_rate column the moment the quotation is written; later
 * edits of the tax definition never alter stored totals. When tax is disabled
 * no tax_id is read and every line is tax-free, regardless of what a forged
 * request sent.
 *
 * Lifecycle (see DECISIONS): create always stores whatever (non-converted)
 * status the request supplied; update/delete are permitted only while the
 * quotation is still a draft (any later status -> 403). The editable header
 * excludes quotation_number, business_id and all computed amounts.
 */
class QuotationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly QuotationCalculator $calculator,
        private readonly BusinessContext $context,
        private readonly BusinessSettings $settings,
        private readonly CurrencyService $currencies,
    ) {
        //
    }

    /**
     * Allocate a number and persist the quotation + items atomically.
     *
     * @param  array{...}  $validated  sanitised StoreQuotationRequest payload
     * @return Quotation the persisted quotation (header amounts populated)
     */
    public function create(array $validated, int $createdBy): Quotation
    {
        return DB::transaction(function () use ($validated, $createdBy) {
            $number = $this->numbers->next(DocumentType::Quotation);
            $calculation = $this->calculation($validated);
            $snapshot = $this->currencySnapshot($validated['date'], $calculation['total'], $validated['currency_code'] ?? null);

            $quotation = new Quotation($this->headerPayload($validated));
            $quotation->forceFill([
                'quotation_number' => $number,
                'subtotal' => $calculation['subtotal'],
                'tax_amount' => $calculation['tax_amount'],
                'total' => $calculation['total'],
                'currency_code' => $snapshot['currency_code'],
                'exchange_rate' => $snapshot['exchange_rate'],
                'base_amount' => $snapshot['base_amount'],
                'created_by' => $createdBy,
            ])->save();

            $items = $this->itemPayloads($validated, $calculation);

            foreach ($items as $index => $item) {
                $item['sort_order'] = $index;
                $quotation->items()->create($item);
            }

            return $quotation->fresh(['items']);
        });
    }

    /**
     * Replace the header + item lines. The draft guard is a hard 403 so the
     * immutable non-draft lifecycle can never be bypassed. The quotation
     * number is immutable.
     */
    public function update(Quotation $quotation, array $validated): void
    {
        $this->assertDraft($quotation);

        DB::transaction(function () use ($quotation, $validated) {
            $calculation = $this->calculation($validated);
            $snapshot = $this->currencySnapshot($validated['date'], $calculation['total'], $validated['currency_code'] ?? null);

            $quotation->fill($this->headerPayload($validated));
            $quotation->forceFill([
                'subtotal' => $calculation['subtotal'],
                'tax_amount' => $calculation['tax_amount'],
                'total' => $calculation['total'],
                'currency_code' => $snapshot['currency_code'],
                'exchange_rate' => $snapshot['exchange_rate'],
                'base_amount' => $snapshot['base_amount'],
            ])->save();

            $quotation->items()->delete();

            $items = $this->itemPayloads($validated, $calculation);

            foreach ($items as $index => $item) {
                $item['sort_order'] = $index;
                $quotation->items()->create($item);
            }
        });
    }

    /**
     * Soft-delete a draft quotation. Only the header row is soft-deleted: item
     * rows have no soft-delete column (approved Batch 14 schema), so they stay
     * behind as the historical snapshot attached to the trashed header.
     * Numbering is never released — the sequence does not rewind, so the
     * deleted number is never reused.
     */
    public function destroy(Quotation $quotation): void
    {
        $this->assertDraft($quotation);
        $quotation->delete();
    }

    /** Whether the quotation can be edited/deleted (still in the draft state). */
    public function isEditable(Quotation $quotation): bool
    {
        return $quotation->status === QuotationStatus::Draft;
    }

    private function assertDraft(Quotation $quotation): void
    {
        if (! $this->isEditable($quotation)) {
            throw new AccessDeniedHttpException('Only draft quotations can be modified.');
        }
    }

    /**
     * Recompute exact totals from the validated payload. Lines are sanitised
     * here (explicit whitelist, tax handled by resolveTax()) so forged keys in
     * the request can never influence the arithmetic.
     */
    private function calculation(array $validated): array
    {
        $lines = [];

        foreach ($validated['items'] as $item) {
            $lines[] = [
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'tax_rate' => $this->resolveTaxRate($item),
            ];
        }

        return $this->calculator->calculate(
            $lines,
            $validated['discount_type'] ?? null,
            $validated['discount_amount'] ?? '0.0000',
        );
    }

    /**
     * The editable header payload (no number, no business_id, no totals).
     */
    private function headerPayload(array $validated): array
    {
        return [
            'customer_id' => $validated['customer_id'],
            'date' => $validated['date'],
            'expiry_date' => $validated['expiry_date'] ?? null,
            'status' => $validated['status'],
            'discount_type' => $validated['discount_type'] ?? null,
            'discount_amount' => $validated['discount_amount'] ?? '0.0000',
            'notes' => $validated['notes'] ?? null,
            'terms' => $validated['terms'] ?? null,
        ];
    }

    /**
     * The Batch 19 currency snapshot (see InvoiceService::currencySnapshot for
     * the shared semantics): the transaction currency — the base currency when
     * the request carried none — its rate at/just before the document date, and
     * the exact base_amount. A missing foreign-currency rate is a 422, never a
     * silent base-rate document.
     *
     * @return array{currency_code: string, exchange_rate: string, base_amount: string}
     */
    private function currencySnapshot(string $date, string $total, ?string $currencyCode): array
    {
        $code = strtoupper($currencyCode ?? $this->currencies->baseCurrency());
        $rate = $this->currencies->resolveOrFail($code, $date);

        return [
            'currency_code' => $code,
            'exchange_rate' => $rate,
            'base_amount' => $this->currencies->toBase($total, $rate),
        ];
    }

    /**
     * Whitelisted item rows with snapshots. tax_id is read ONLY while the tax
     * feature is enabled; otherwise every line is written tax-free even if the
     * request carried a tax_id.
     */
    private function itemPayloads(array $validated, array $calculation): array
    {
        $taxesEnabled = (bool) $this->settings->get('general.tax_enabled', false);
        $rows = [];

        foreach ($validated['items'] as $index => $item) {
            $rows[] = [
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'tax_id' => $taxesEnabled ? ($item['tax_id'] ?? null) : null,
                'tax_rate' => $taxesEnabled ? $this->resolveTaxRate($item) : null,
                'line_subtotal' => $calculation['items'][$index]['line_subtotal'],
                'line_tax' => $calculation['items'][$index]['line_tax'],
                'line_total' => $calculation['items'][$index]['line_total'],
            ];
        }

        return $rows;
    }

    /**
     * Current-bubble tax rate for a line. Returns null when tax is disabled or
     * the line carries no tax_id. Re-reads the ACTIVE current-business Tax row
     * so the report/snapshot is authoritative regardless of what the request
     * claimed; a missing row (should be impossible after validation) is a
     * RuntimeException rather than a silent zero.
     */
    private function resolveTaxRate(array $item): ?string
    {
        $taxId = $item['tax_id'] ?? null;

        if ($taxId === null) {
            return null;
        }

        if (! (bool) $this->settings->get('general.tax_enabled', false)) {
            return null;
        }

        $businessId = $this->context->currentId() ?? throw new RuntimeException('A current business is required.');

        $tax = Tax::query()
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->find($taxId);

        if ($tax === null) {
            throw new RuntimeException('Tax referenced by quotation item does not exist for the current business.');
        }

        return $tax->rate;
    }
}
