<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\Expense;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Business-scoped expense lifecycle (Batch 17).
 *
 * The controller stays thin; every financial/security rule lives here:
 *
 * - expense_number is allocated by DocumentNumberService (DocumentType::Expense,
 *   EXP-000001) inside the same transaction as the insert, preserving the Batch
 *   13 concurrency/numbering guarantees. A request can never supply a number.
 * - business_id is assigned by the BelongsToBusiness trait from the current
 *   BusinessContext; a request-supplied business_id is never read.
 * - amounts are always DECIMAL(16,4) strings; no float arithmetic. expense_date
 *   is a business date. Batch 19 adds the currency snapshot: an expense records
 *   the transaction currency (base currency by default), its rate at/just before
 *   the expense date, and base_amount (exact 4-dp conversion) — the same
 *   permanent snapshot the sales documents carry.
 * - receipt files are optional, validated by the form request against a strict
 *   whitelist (jpg/jpeg/png/webp/pdf), stored on the PRIVATE local disk under
 *   receipts/{business_id}/ with a generated random filename, and never name-
 *   derived from client input. Replacing a receipt deletes the previous file;
 *   removing a receipt deletes the file and clears the path. A soft-deleted
 *   expense keeps its file (financial history is never destroyed).
 * - receipt serving is an authenticated, permission-gated, tenant-scoped route:
 *   only the owning business's expense can stream its own file.
 */
class ExpenseService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly BusinessContext $context,
        private readonly CurrencyService $currencies,
        private readonly AccountingPostingService $accounting,
    ) {
        //
    }

    /** @var string private disk: shared-hosting friendly, never depends on cloud storage */
    private const RECEIPT_DISK = 'local';

    /**
     * Create an expense, allocating its number transactionally.
     *
     * @param  array<string, mixed>  $data  validated store payload
     */
    public function store(array $data, ?UploadedFile $receipt, int $userId): Expense
    {
        return DB::transaction(function () use ($data, $receipt, $userId) {
            $expense = new Expense;

            $expense->expense_number = $this->numbers->next(DocumentType::Expense);
            $expense->category_id = $data['category_id'] ?? null;
            $expense->expense_date = $data['expense_date'];
            $expense->amount = Decimal::normalize($data['amount']);
            $expense->payment_method = $data['payment_method'] ?? null;
            $expense->reference = $data['reference'] ?? null;
            $expense->vendor = $data['vendor'] ?? null;
            $expense->notes = $data['notes'] ?? null;
            $expense->created_by = $userId;

            $snapshot = $this->currencySnapshot($data['expense_date'], $expense->amount, $data['currency_code'] ?? null);
            $expense->currency_code = $snapshot['currency_code'];
            $expense->exchange_rate = $snapshot['exchange_rate'];
            $expense->base_amount = $snapshot['base_amount'];

            if ($receipt !== null) {
                $expense->receipt_path = $this->storeReceipt($receipt);
            }

            $expense->save();
            $this->accounting->postExpense($expense);

            return $expense;
        });
    }

    /**
     * Update the editable expense fields, optionally replacing or removing the
     * receipt. The expense_number is never touched.
     *
     * @param  array<string, mixed>  $data  validated update payload
     */
    public function update(Expense $expense, array $data, ?UploadedFile $receipt, bool $removeReceipt, int $userId): Expense
    {
        return DB::transaction(function () use ($expense, $data, $receipt, $removeReceipt) {
            $expense->category_id = $data['category_id'] ?? null;
            $expense->expense_date = $data['expense_date'];
            $expense->amount = Decimal::normalize($data['amount']);
            $expense->payment_method = $data['payment_method'] ?? null;
            $expense->reference = $data['reference'] ?? null;
            $expense->vendor = $data['vendor'] ?? null;
            $expense->notes = $data['notes'] ?? null;

            $snapshot = $this->currencySnapshot($data['expense_date'], $expense->amount, $data['currency_code'] ?? null);
            $expense->currency_code = $snapshot['currency_code'];
            $expense->exchange_rate = $snapshot['exchange_rate'];
            $expense->base_amount = $snapshot['base_amount'];

            if ($receipt !== null) {
                $this->deleteReceiptFile($expense->receipt_path);
                $expense->receipt_path = $this->storeReceipt($receipt);
            } elseif ($removeReceipt && $expense->receipt_path !== null) {
                $this->deleteReceiptFile($expense->receipt_path);
                $expense->receipt_path = null;
            }

            $expense->save();
            $this->accounting->replaceExpense($expense);

            return $expense;
        });
    }

    /**
     * Soft-delete the expense. The row, its number, and its receipt file are
     * kept as a financial audit artifact; normal queries simply stop showing it.
     */
    public function destroy(Expense $expense): void
    {
        DB::transaction(function () use ($expense): void {
            $this->accounting->reverseExpense($expense);
            $expense->delete();
        });
    }

    /**
     * The Batch 19 currency snapshot (see InvoiceService::currencySnapshot for
     * the shared semantics): transaction currency defaulting to the base, its
     * rate at/just before the expense date, and the exact base_amount. A
     * missing foreign-currency rate fails with a 422, never a silent document.
     *
     * @return array{currency_code: string, exchange_rate: string, base_amount: string}
     */
    private function currencySnapshot(string $date, string $amount, ?string $currencyCode): array
    {
        $code = strtoupper($currencyCode ?? $this->currencies->baseCurrency());
        $rate = $this->currencies->resolveOrFail($code, $date);

        return [
            'currency_code' => $code,
            'exchange_rate' => $rate,
            'base_amount' => $this->currencies->toBase($amount, $rate),
        ];
    }

    /**
     * Stream the receipt file for direct HTTP access. Tenancy and permission
     * are enforced by the route middleware + the business global scope before
     * this method is ever reached; a missing file yields a 404.
     */
    public function receiptResponse(Expense $expense): StreamedResponse
    {
        $path = $this->receiptAbsolutePath($expense);

        if ($path === null || ! is_file($path)) {
            throw new RuntimeException('Receipt file not found.');
        }

        $mime = Storage::disk(self::RECEIPT_DISK)->mimeType($expense->receipt_path)
            ?: 'application/octet-stream';

        return response()->stream(function () use ($path) {
            $stream = fopen($path, 'rb');

            if ($stream === false) {
                abort(404, 'Receipt file not found.');
            }

            fpassthru($stream);
            fclose($stream);
        }, 200, ['Content-Type' => $mime]);
    }

    /**
     * Lightweight operational expense report (Batch 17 only — not the Batch 23
     * reporting engine). Returns matching expenses plus the exact DECIMAL total.
     *
     * @return array{expenses: Collection<int, Expense>, total: string}
     */
    public function report(array $filters = []): array
    {
        $query = Expense::query()
            ->with(['category', 'createdBy'])
            ->orderBy('expense_date', 'desc')
            ->orderBy('id', 'desc');

        $categoryId = $filters['category_id'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;

        if (! blank($categoryId)) {
            $query->where('category_id', (int) $categoryId);
        }

        if (! blank($dateFrom)) {
            $query->whereDate('expense_date', '>=', $dateFrom);
        }

        if (! blank($dateTo)) {
            $query->whereDate('expense_date', '<=', $dateTo);
        }

        $expenses = $query->get();

        $total = '0';
        foreach ($expenses as $expense) {
            $total = Decimal::add($total, $expense->base_amount);
        }

        return ['expenses' => $expenses, 'total' => $total];
    }

    private function storeReceipt(UploadedFile $receipt): string
    {
        $businessId = $this->businessIdOrFail();

        // Generated random name — the client-supplied filename is never used.
        $name = Str::random(40).'.'.$receipt->getClientOriginalExtension();

        return $receipt->storeAs('receipts/'.$businessId, $name, [
            'disk' => self::RECEIPT_DISK,
        ]);
    }

    private function deleteReceiptFile(?string $path): void
    {
        if ($path === null) {
            return;
        }

        if (Storage::disk(self::RECEIPT_DISK)->exists($path)) {
            Storage::disk(self::RECEIPT_DISK)->delete($path);
        }
    }

    private function receiptAbsolutePath(Expense $expense): ?string
    {
        if ($expense->receipt_path === null) {
            return null;
        }

        return Storage::disk(self::RECEIPT_DISK)->path($expense->receipt_path);
    }

    private function businessIdOrFail(): int
    {
        $businessId = $this->context->currentId();

        if ($businessId === null) {
            throw new RuntimeException('A current business is required to store a receipt.');
        }

        return $businessId;
    }
}
