<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\Tax;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Invoice create/update/convert orchestration (Batch 15).
 *
 * The service owns the invoice write path: it opens the outer transaction,
 * allocates the document number from DocumentNumberService (whose own
 * transaction nests as a savepoint) and stores the header + item snapshot rows
 * so a failure rolls back the number as well. Business ownership comes
 * automatically from BusinessContext/BelongsToBusiness; this service never
 * accepts a business_id, invoice_number or any computed total from a request.
 * Totals are recomputed by the shared QuotationCalculator from the sanitised
 * lines (identical arithmetic for quotations and invoices).
 *
 * Tax snapshots follow the Batch 14 rule: while general.tax_enabled is on,
 * each line's tax_id is re-resolved to the ACTIVE current-business Tax row and
 * its `rate` is copied into the item's tax_rate column the moment the invoice
 * is written; later edits of the tax definition never alter stored totals.
 *
 * Lifecycle (see DECISIONS): create always stores whatever valid (non-paid)
 * status the request supplied (draft|sent); update/delete are permitted only
 * while the invoice is still a draft (any later status -> 403). The editable
 * header excludes invoice_number, business_id and all computed amounts.
 *
 * convert(): the quotation -> invoice path. One transaction locks the source
 * quotation row FOR UPDATE, re-checks convertibility, allocates the number,
 * copies the customer + item snapshots verbatim (no-drift: totals recomputed
 * by the same calculator from the identical snapshot values, so they always
 * equal the quotation's stored totals), persists the invoice, then marks the
 * quotation Converted. The DB-unique constraint on invoices.quotation_id makes
 * the one-to-one rule true even if two requests race. A concurrent or already
 * converted quotation loses with a safe 422 ValidationException and leaves no
 * invoice and no consumed number.
 */
class InvoiceService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly QuotationCalculator $calculator,
        private readonly BusinessContext $context,
        private readonly BusinessSettings $settings,
        private readonly CurrencyService $currencies,
        private readonly PaymentService $payments,
        private readonly AccountingPostingService $accounting,
    ) {
        //
    }

    /**
     * Allocate a number and persist the invoice + items atomically.
     *
     * @param  array{...}  $validated  sanitised StoreInvoiceRequest payload
     * @return Invoice the persisted invoice (header amounts populated)
     */
    public function create(array $validated, int $createdBy): Invoice
    {
        return DB::transaction(function () use ($validated, $createdBy) {
            $number = $this->numbers->next(DocumentType::Invoice);
            $calculation = $this->calculation($validated);
            $snapshot = $this->currencySnapshot($validated['date'], $calculation['total'], $validated['currency_code'] ?? null);

            $invoice = new Invoice($this->headerPayload($validated));
            $invoice->forceFill([
                'invoice_number' => $number,
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
                $invoice->items()->create($item);
            }

            // Synchronise the payment balance caches so a freshly created
            // invoice is immediately consistent (amount_due = total) without
            // needing a first payment to trigger the reconciliation.
            $this->payments->reconcileBalance($invoice);

            if ($invoice->status !== InvoiceStatus::Draft) {
                $this->accounting->postInvoice($invoice);
            }

            return $invoice->fresh(['items']);
        });
    }

    /**
     * Replace the header + item lines. The draft guard is a hard 403 so the
     * immutable non-draft lifecycle can never be bypassed. The invoice number
     * is immutable.
     */
    public function update(Invoice $invoice, array $validated): void
    {
        $this->assertDraft($invoice);

        DB::transaction(function () use ($invoice, $validated) {
            $calculation = $this->calculation($validated);
            $snapshot = $this->currencySnapshot($validated['date'], $calculation['total'], $validated['currency_code'] ?? null);

            $invoice->fill($this->headerPayload($validated));
            $invoice->forceFill([
                'subtotal' => $calculation['subtotal'],
                'tax_amount' => $calculation['tax_amount'],
                'total' => $calculation['total'],
                'currency_code' => $snapshot['currency_code'],
                'exchange_rate' => $snapshot['exchange_rate'],
                'base_amount' => $snapshot['base_amount'],
            ])->save();

            $invoice->items()->delete();

            $items = $this->itemPayloads($validated, $calculation);

            foreach ($items as $index => $item) {
                $item['sort_order'] = $index;
                $invoice->items()->create($item);
            }

            // Drafts keep the balance caches truthful after the totals change.
            $this->payments->reconcileBalance($invoice);

            if ($invoice->status !== InvoiceStatus::Draft) {
                $this->accounting->postInvoice($invoice);
            }
        });
    }

    /**
     * Soft-delete a draft invoice. Deletion is never allowed on a converted
     * invoice: an invoice that owns a converted quotation is permanently
     * visible. Only the header row is soft-deleted: item rows have no soft-
     * delete column (approved Batch 15 schema), so they stay behind as the
     * historical snapshot. Numbering is never released — the sequence does not
     * rewind, so the deleted number is never reused.
     */
    public function destroy(Invoice $invoice): void
    {
        $this->assertDraft($invoice);
        $invoice->delete();
    }

    /** Whether the invoice can be edited/deleted (still in the draft state). */
    public function isEditable(Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Draft;
    }

    /**
     * Convert a quotation into the single invoice that owns it.
     *
     * Runs inside one transaction with a FOR UPDATE lock on the source
     * quotation row so two concurrent conversions can never both succeed: the
     * second locker re-checks the (now Converted) status and fails with a safe
     * 422 before any number is allocated or any row is written. Convertibility
     * is intentionally business-restricted (only the current business can see
     * and convert its own quotations).
     *
     * No-drift rule: the invoice copies the quotation's customer, discount and
     * per-item snapshots (description, quantity, unit_price, tax_rate) and
     * recomputes totals from exactly those snapshots with the same calculator,
     * so the invoice totals always equal the quotation's stored totals. Live
     * Product/Tax master data never re-enters the calculation.
     *
     * Converts any non-converted quotation of the current business — even a
     * soft-deleted or rejected/expired one — because the operator is trusting
     * the approved snapshot, not re-negotiating it. The resulting invoice is
     * always `sent` and final.
     *
     * @return Invoice the sent invoice (header amounts + items populated)
     *
     * @throws ValidationException when the quotation is not convertible here
     */
    public function convert(Quotation $quotation, int $createdBy): Invoice
    {
        if (! $this->context->isCurrent($quotation)) {
            throw ValidationException::withMessages([
                'quotation' => __('quotations.validation.not_convertible'),
            ]);
        }

        return DB::transaction(function () use ($quotation, $createdBy) {
            // Lock the source row so a racing identical conversion blocks here
            // and re-reads the row AFTER the winner has committed, at which
            // point the status check below fails safely. Locking a row we were
            // already given keeps the reference authoritative (fresh casts).
            $locked = Quotation::query()
                ->whereKey($quotation->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status === QuotationStatus::Converted) {
                throw ValidationException::withMessages([
                    'quotation' => __('quotations.validation.not_convertible'),
                ]);
            }

            $number = $this->numbers->next(DocumentType::Invoice);
            $calculation = $this->calculationFromLocked($locked);

            // Batch 19 no-drift currency: the invoice keeps the quotation's
            // currency AND the rate the quotation was priced at; only the
            // base_amount is recomputed from the (identical) recomputed total.
            $rate = $locked->exchange_rate ?? '1';
            $code = $locked->currency_code ?? $this->currencies->baseCurrency();

            $invoice = new Invoice;
            $invoice->forceFill([
                'invoice_number' => $number,
                'customer_id' => $locked->customer_id,
                'quotation_id' => $locked->id,
                'date' => now()->toDateString(),
                'status' => InvoiceStatus::Sent->value,
                'discount_type' => $locked->discount_type,
                'discount_amount' => $locked->discount_amount ?? '0.0000',
                'subtotal' => $calculation['subtotal'],
                'tax_amount' => $calculation['tax_amount'],
                'total' => $calculation['total'],
                'currency_code' => $code,
                'exchange_rate' => $rate,
                'base_amount' => $this->currencies->toBase($calculation['total'], $rate),
                'notes' => $locked->notes,
                'created_by' => $createdBy,
            ])->save();

            foreach ($locked->items->sortBy('sort_order')->values() as $index => $item) {
                $invoice->items()->create([
                    'product_id' => $item->product_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'tax_id' => $item->tax_id,
                    'tax_rate' => $item->tax_rate,
                    'line_subtotal' => $calculation['items'][$index]['line_subtotal'],
                    'line_tax' => $calculation['items'][$index]['line_tax'],
                    'line_total' => $calculation['items'][$index]['line_total'],
                    'sort_order' => $item->sort_order,
                ]);
            }

            // Terminal transition: the quotation can never be converted again
            // (the DB unique constraint on invoices.quotation_id is the
            // backstop if this status write ever raced). No request can undo it.
            $locked->status = QuotationStatus::Converted->value;
            $locked->save();

            // A converted invoice is Sent and immediately payable, so its
            // balance caches must be initialised (amount_due = total) here.
            $this->payments->reconcileBalance($invoice);
            $this->accounting->postInvoice($invoice);

            return $invoice->fresh(['items']);
        });
    }

    private function assertDraft(Invoice $invoice): void
    {
        if (! $this->isEditable($invoice)) {
            throw new AccessDeniedHttpException('Only draft invoices can be modified.');
        }
    }

    /**
     * Recompute exact totals from the validated payload. Lines are sanitised
     * here (explicit whitelist, tax handled by resolveTaxRate()) so forged keys
     * in the request can never influence the arithmetic.
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
     * No-drift conversion totals: recomputed from the quotation's OWN stored
     * snapshots (never live data), which by construction reproduce the stored
     * quotation totals exactly.
     */
    private function calculationFromLocked(Quotation $locked): array
    {
        $lines = [];

        foreach ($locked->items->sortBy('sort_order')->values() as $item) {
            $lines[] = [
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'tax_rate' => $item->tax_rate,
            ];
        }

        return $this->calculator->calculate(
            $lines,
            $locked->discount_type,
            $locked->discount_amount ?? '0.0000',
        );
    }

    /**
     * The editable header payload (no number, no business_id, no totals, no
     * quotation reference — the source quotation is set only by convert()).
     */
    private function headerPayload(array $validated): array
    {
        return [
            'customer_id' => $validated['customer_id'],
            'date' => $validated['date'],
            'status' => $validated['status'],
            'discount_type' => $validated['discount_type'] ?? null,
            'discount_amount' => $validated['discount_amount'] ?? '0.0000',
            'notes' => $validated['notes'] ?? null,
        ];
    }

    /**
     * The Batch 19 currency snapshot: the (validated) transaction currency —
     * defaulting to the business base when the request carried none — its rate
     * in effect at the document date (strictly at-or-before it, never after),
     * and the exact DECIMAL(16,4) base_amount. A missing rate for a foreign
     * currency fails with a 422 on currency_code before anything is written.
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
     * so the stored snapshot is authoritative regardless of what the request
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
            throw new RuntimeException('Tax referenced by invoice item does not exist for the current business.');
        }

        return $tax->rate;
    }
}
