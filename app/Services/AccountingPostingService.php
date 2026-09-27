<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\AccountingPosting;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\GoodsReceipt;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountingPostingService
{
    public function postInvoice(Invoice $invoice): ?JournalEntry
    {
        if ($invoice->status === InvoiceStatus::Draft) {
            return null;
        }

        $baseTotal = Decimal::normalize((string) ($invoice->base_amount ?? $invoice->total));
        $rate = (string) ($invoice->exchange_rate ?? '1');
        $baseTax = Decimal::round(Decimal::mul((string) $invoice->tax_amount, $rate), 4);
        $baseRevenue = Decimal::sub($baseTotal, $baseTax);

        $lines = [
            $this->line('AUTO-AR', 'Accounts Receivable', 'asset', $baseTotal, '0', $invoice->invoice_number),
            $this->line('AUTO-SALES', 'Sales Revenue', 'income', '0', $baseRevenue, $invoice->invoice_number),
        ];

        if (! Decimal::isZero($baseTax)) {
            $lines[] = $this->line('AUTO-TAX', 'Tax Payable', 'liability', '0', $baseTax, $invoice->invoice_number);
        }

        return $this->post(
            Invoice::class,
            $invoice->id,
            'issued',
            'AUTO-'.$invoice->invoice_number,
            $invoice->date->toDateString(),
            'Invoice '.$invoice->invoice_number,
            $lines,
        );
    }

    public function postPayment(Payment $payment): JournalEntry
    {
        $amount = Decimal::normalize((string) ($payment->base_amount ?? $payment->amount));
        $cash = $this->paymentAccount((string) $payment->payment_method);

        if ($payment->party_type === 'supplier') {
            return $this->post(
                Payment::class,
                $payment->id,
                'supplier-payment',
                'AUTO-'.$payment->payment_number,
                $payment->payment_date->toDateString(),
                'Supplier payment '.$payment->payment_number,
                [
                    $this->line('AUTO-AP', 'Accounts Payable', 'liability', $amount, '0', $payment->payment_number),
                    $this->line($cash['code'], $cash['name'], 'asset', '0', $amount, $payment->payment_number),
                ],
            );
        }

        $payment->loadMissing('invoice');
        $historicalRate = (string) ($payment->invoice?->exchange_rate ?? '1');
        $carryingAmount = Decimal::round(Decimal::mul((string) $payment->amount, $historicalRate));
        $lines = [
            $this->line($cash['code'], $cash['name'], 'asset', $amount, '0', $payment->payment_number),
            $this->line('AUTO-AR', 'Accounts Receivable', 'asset', '0', $carryingAmount, $payment->payment_number),
        ];

        if (Decimal::gt($amount, $carryingAmount)) {
            $lines[] = $this->line(
                'AUTO-FX-GAIN',
                'Realized Foreign Exchange Gain',
                'income',
                '0',
                Decimal::sub($amount, $carryingAmount),
                $payment->payment_number,
            );
        } elseif (Decimal::lt($amount, $carryingAmount)) {
            $lines[] = $this->line(
                'AUTO-FX-LOSS',
                'Realized Foreign Exchange Loss',
                'expense',
                Decimal::sub($carryingAmount, $amount),
                '0',
                $payment->payment_number,
            );
        }

        return $this->post(
            Payment::class,
            $payment->id,
            'customer-payment',
            'AUTO-'.$payment->payment_number,
            $payment->payment_date->toDateString(),
            'Customer payment '.$payment->payment_number,
            $lines,
        );
    }

    public function reversePayment(Payment $payment, ?string $reason = null): ?JournalEntry
    {
        $event = $payment->party_type === 'supplier' ? 'supplier-payment' : 'customer-payment';

        return $this->reverse(
            Payment::class,
            $payment->id,
            $event,
            $reason ?: 'Payment reversed',
        );
    }

    public function postGoodsReceipt(GoodsReceipt $receipt): JournalEntry
    {
        $receipt->loadMissing('purchaseOrder');
        $amount = Decimal::normalize((string) $receipt->total);
        $invoiceRecognition = $receipt->purchaseOrder?->ap_recognition === 'invoice';
        $liabilityCode = $invoiceRecognition ? 'AUTO-GRNI' : 'AUTO-AP';
        $liabilityName = $invoiceRecognition ? 'Goods Received Not Invoiced' : 'Accounts Payable';

        return $this->post(
            GoodsReceipt::class,
            $receipt->id,
            'posted',
            'AUTO-'.$receipt->number,
            $receipt->receipt_date->toDateString(),
            'Goods receipt '.$receipt->number,
            [
                $this->line('AUTO-INVENTORY', 'Inventory', 'asset', $amount, '0', $receipt->number),
                $this->line($liabilityCode, $liabilityName, 'liability', '0', $amount, $receipt->number),
            ],
        );
    }

    public function postSupplierInvoice(SupplierInvoice $invoice): ?JournalEntry
    {
        $invoice->loadMissing('purchaseOrder');

        if ($invoice->status !== 'approved' || $invoice->purchaseOrder?->ap_recognition !== 'invoice') {
            return null;
        }

        $basis = Decimal::normalize((string) $invoice->po_basis_total);
        $total = Decimal::normalize((string) $invoice->total);
        $variance = Decimal::normalize((string) $invoice->price_variance_total);
        $lines = [
            $this->line('AUTO-GRNI', 'Goods Received Not Invoiced', 'liability', $basis, '0', $invoice->number),
        ];

        if (Decimal::gt($variance, '0')) {
            $lines[] = $this->line(
                'AUTO-PPV',
                'Purchase Price Variance',
                'expense',
                $variance,
                '0',
                $invoice->number,
            );
        } elseif (Decimal::lt($variance, '0')) {
            $lines[] = $this->line(
                'AUTO-PPV',
                'Purchase Price Variance',
                'expense',
                '0',
                Decimal::sub('0.0000', $variance),
                $invoice->number,
            );
        }

        $lines[] = $this->line('AUTO-AP', 'Accounts Payable', 'liability', '0', $total, $invoice->number);

        return $this->post(
            SupplierInvoice::class,
            $invoice->id,
            'approved',
            'AUTO-'.$invoice->number,
            $invoice->invoice_date->toDateString(),
            'Supplier invoice '.$invoice->number.' · '.$invoice->supplier_invoice_number,
            $lines,
        );
    }

    public function postPurchaseReceipt(PurchaseOrder $order): JournalEntry
    {
        $amount = Decimal::normalize((string) $order->total);

        return $this->post(
            PurchaseOrder::class,
            $order->id,
            'received',
            'AUTO-'.$order->number,
            $order->order_date->toDateString(),
            'Purchase receipt '.$order->number,
            [
                $this->line('AUTO-INVENTORY', 'Inventory', 'asset', $amount, '0', $order->number),
                $this->line('AUTO-AP', 'Accounts Payable', 'liability', '0', $amount, $order->number),
            ],
        );
    }

    public function postPurchaseReturn(InventoryReturn $return): ?JournalEntry
    {
        if ($return->type !== 'purchase') {
            return null;
        }

        $amount = Decimal::normalize((string) $return->total);
        $order = $return->source_type === PurchaseOrder::class
            ? PurchaseOrder::query()->find($return->source_id)
            : null;
        $invoiceRecognition = $order?->ap_recognition === 'invoice';
        $liabilityCode = $invoiceRecognition ? 'AUTO-GRNI' : 'AUTO-AP';
        $liabilityName = $invoiceRecognition ? 'Goods Received Not Invoiced' : 'Accounts Payable';

        return $this->post(
            InventoryReturn::class,
            $return->id,
            'purchase-return',
            'AUTO-'.$return->number,
            $return->processed_at->toDateString(),
            'Purchase return '.$return->number,
            [
                $this->line($liabilityCode, $liabilityName, 'liability', $amount, '0', $return->number),
                $this->line('AUTO-INVENTORY', 'Inventory', 'asset', '0', $amount, $return->number),
            ],
        );
    }

    public function postSupplierOpeningBalance(Supplier $supplier, bool $newRevision = false): ?JournalEntry
    {
        if (! $newRevision) {
            $existing = AccountingPosting::query()
                ->where('source_type', Supplier::class)
                ->where('source_id', $supplier->id)
                ->with('journalEntry')
                ->latest('id')
                ->first();

            if ($existing !== null) {
                return $existing->journalEntry;
            }
        }

        $amount = Decimal::normalize((string) ($supplier->opening_balance ?? '0'));

        if (! Decimal::gt($amount, '0')) {
            return null;
        }

        $revision = AccountingPosting::query()
            ->where('source_type', Supplier::class)
            ->where('source_id', $supplier->id)
            ->count() + 1;

        return $this->post(
            Supplier::class,
            $supplier->id,
            'opening-balance-'.$revision,
            'AUTO-SUP-'.$supplier->code.'-'.$revision,
            ($supplier->opening_balance_date ?? $supplier->created_at)->toDateString(),
            'Supplier opening balance '.$supplier->code.' revision '.$revision,
            [
                $this->line('AUTO-OPENING', 'Opening Balance Equity', 'equity', $amount, '0', $supplier->code),
                $this->line('AUTO-AP', 'Accounts Payable', 'liability', '0', $amount, $supplier->code),
            ],
        );
    }

    public function replaceSupplierOpeningBalance(Supplier $supplier): ?JournalEntry
    {
        $this->reverseActiveSource(Supplier::class, $supplier->id, 'Supplier opening balance updated');

        return $this->postSupplierOpeningBalance($supplier, true);
    }

    public function reverseSupplierOpeningBalance(Supplier $supplier): ?JournalEntry
    {
        return $this->reverseActiveSource(Supplier::class, $supplier->id, 'Supplier opening balance removed');
    }

    public function postExpense(Expense $expense, bool $newRevision = false): JournalEntry
    {
        if (! $newRevision) {
            $existing = AccountingPosting::query()
                ->where('source_type', Expense::class)
                ->where('source_id', $expense->id)
                ->with('journalEntry')
                ->latest('id')
                ->first();

            if ($existing !== null) {
                return $existing->journalEntry;
            }
        }

        $revision = AccountingPosting::query()
            ->where('source_type', Expense::class)
            ->where('source_id', $expense->id)
            ->count() + 1;

        $amount = Decimal::normalize((string) ($expense->base_amount ?? $expense->amount));
        $cash = $this->paymentAccount($expense->payment_method?->value ?? (string) $expense->payment_method);

        return $this->post(
            Expense::class,
            $expense->id,
            'revision-'.$revision,
            'AUTO-'.$expense->expense_number.'-'.$revision,
            $expense->expense_date->toDateString(),
            'Expense '.$expense->expense_number.' revision '.$revision,
            [
                $this->line('AUTO-EXPENSE', 'General Expenses', 'expense', $amount, '0', $expense->expense_number),
                $this->line($cash['code'], $cash['name'], 'asset', '0', $amount, $expense->expense_number),
            ],
        );
    }

    public function replaceExpense(Expense $expense): JournalEntry
    {
        $this->reverseActiveSource(Expense::class, $expense->id, 'Expense updated');

        return $this->postExpense($expense, true);
    }

    public function reverseExpense(Expense $expense): ?JournalEntry
    {
        return $this->reverseActiveSource(Expense::class, $expense->id, 'Expense deleted');
    }

    public function postAssetAcquisition(FixedAsset $asset, string $paymentMethod): JournalEntry
    {
        $asset->loadMissing('category');
        $category = $asset->category;
        $cash = $this->paymentAccount($paymentMethod);
        $amount = Decimal::normalize((string) $asset->acquisition_cost);

        return $this->post(
            FixedAsset::class,
            $asset->id,
            'acquisition',
            'AUTO-ASSET-'.$asset->asset_number,
            $asset->acquisition_date->toDateString(),
            'Asset acquisition '.$asset->asset_number.' — '.$asset->name,
            [
                $this->line(
                    $category->asset_account_code,
                    'Fixed Assets - '.$category->name,
                    'asset',
                    $amount,
                    '0',
                    $asset->asset_number,
                    $asset->cost_center_id,
                ),
                $this->line(
                    $cash['code'],
                    $cash['name'],
                    'asset',
                    '0',
                    $amount,
                    $asset->asset_number,
                ),
            ],
        );
    }

    public function postAssetDepreciation(FixedAsset $asset, string $date, string $amount): JournalEntry
    {
        $asset->loadMissing('category');
        $category = $asset->category;
        $amount = Decimal::normalize($amount);

        return $this->post(
            FixedAsset::class,
            $asset->id,
            'depreciation-'.$date,
            'AUTO-DEP-'.$asset->asset_number.'-'.$date,
            $date,
            'Depreciation '.$asset->asset_number.' through '.$date,
            [
                $this->line(
                    $category->depreciation_expense_account_code,
                    'Depreciation Expense - '.$category->name,
                    'expense',
                    $amount,
                    '0',
                    $asset->asset_number,
                    $asset->cost_center_id,
                ),
                $this->line(
                    $category->accumulated_depreciation_account_code,
                    'Accumulated Depreciation - '.$category->name,
                    'asset',
                    '0',
                    $amount,
                    $asset->asset_number,
                    $asset->cost_center_id,
                ),
            ],
        );
    }

    public function postAssetDisposal(FixedAsset $asset, string $date, string $proceeds): JournalEntry
    {
        $asset->loadMissing('category');
        $category = $asset->category;
        $cost = Decimal::normalize((string) $asset->acquisition_cost);
        $accumulated = Decimal::normalize((string) $asset->accumulated_depreciation);
        $bookValue = Decimal::normalize((string) $asset->book_value);
        $proceeds = Decimal::normalize($proceeds);
        $lines = [];

        if (Decimal::gt($proceeds, '0')) {
            $lines[] = $this->line('AUTO-CASH', 'Cash', 'asset', $proceeds, '0', $asset->asset_number);
        }

        if (Decimal::gt($accumulated, '0')) {
            $lines[] = $this->line(
                $category->accumulated_depreciation_account_code,
                'Accumulated Depreciation - '.$category->name,
                'asset',
                $accumulated,
                '0',
                $asset->asset_number,
            );
        }

        if (Decimal::lt($proceeds, $bookValue)) {
            $loss = Decimal::sub($bookValue, $proceeds);
            $lines[] = $this->line('AUTO-ASSET-LOSS', 'Loss on Asset Disposal', 'expense', $loss, '0', $asset->asset_number);
        } elseif (Decimal::gt($proceeds, $bookValue)) {
            $gain = Decimal::sub($proceeds, $bookValue);
            $lines[] = $this->line('AUTO-ASSET-GAIN', 'Gain on Asset Disposal', 'income', '0', $gain, $asset->asset_number);
        }

        $lines[] = $this->line(
            $category->asset_account_code,
            'Fixed Assets - '.$category->name,
            'asset',
            '0',
            $cost,
            $asset->asset_number,
        );

        return $this->post(
            FixedAsset::class,
            $asset->id,
            'disposal',
            'AUTO-DISPOSE-'.$asset->asset_number,
            $date,
            'Asset disposal '.$asset->asset_number.' — '.$asset->name,
            $lines,
        );
    }

    /**
     * @param  list<array{account:Account,debit:string,credit:string,memo:?string}>  $lines
     */
    private function post(
        string $sourceType,
        int $sourceId,
        string $eventKey,
        string $number,
        string $date,
        string $description,
        array $lines,
    ): JournalEntry {
        app(FiscalPeriodService::class)->assertPostingAllowed($date);

        return DB::transaction(function () use ($sourceType, $sourceId, $eventKey, $number, $date, $description, $lines): JournalEntry {
            $existing = AccountingPosting::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('event_key', $eventKey)
                ->with('journalEntry')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing->journalEntry;
            }

            $debit = '0.0000';
            $credit = '0.0000';

            foreach ($lines as $line) {
                $debit = Decimal::add($debit, $line['debit']);
                $credit = Decimal::add($credit, $line['credit']);
            }

            if (! Decimal::eq($debit, $credit)) {
                throw new RuntimeException('Accounting posting is not balanced.');
            }

            $entry = JournalEntry::create([
                'number' => mb_substr($number, 0, 80),
                'entry_date' => $date,
                'status' => 'posted',
                'description' => $description,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account']->id,
                    'debit' => $line['debit'],
                    'credit' => $line['credit'],
                    'memo' => $line['memo'],
                    'cost_center_id' => $line['cost_center_id'] ?? null,
                ]);
            }

            AccountingPosting::create([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'event_key' => $eventKey,
                'journal_entry_id' => $entry->id,
            ]);

            return $entry->load('lines.account');
        });
    }

    private function reverse(
        string $sourceType,
        int $sourceId,
        string $eventKey,
        string $reason,
    ): ?JournalEntry {
        return DB::transaction(function () use ($sourceType, $sourceId, $eventKey, $reason): ?JournalEntry {
            $posting = AccountingPosting::query()
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('event_key', $eventKey)
                ->with('journalEntry.lines.account')
                ->lockForUpdate()
                ->first();

            if ($posting === null) {
                return null;
            }

            if ($posting->reversed_at !== null) {
                return $posting->reversalJournalEntry;
            }

            $original = $posting->journalEntry;
            $number = mb_substr('REV-'.$original->number, 0, 80);
            $reversalDate = now()->toDateString();
            app(FiscalPeriodService::class)->assertPostingAllowed($reversalDate);

            $reversal = JournalEntry::create([
                'number' => $number,
                'entry_date' => $reversalDate,
                'status' => 'posted',
                'description' => $reason.' — reversal of '.$original->number,
                'source_type' => 'reversal:'.$sourceType,
                'source_id' => $sourceId,
            ]);

            foreach ($original->lines as $line) {
                $reversal->lines()->create([
                    'account_id' => $line->account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'memo' => $reason,
                ]);
            }

            $posting->update([
                'reversal_journal_entry_id' => $reversal->id,
                'reversed_at' => now(),
            ]);

            return $reversal->load('lines.account');
        });
    }

    private function reverseActiveSource(string $sourceType, int $sourceId, string $reason): ?JournalEntry
    {
        $posting = AccountingPosting::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereNull('reversed_at')
            ->latest('id')
            ->first();

        if ($posting === null) {
            return null;
        }

        return $this->reverse($sourceType, $sourceId, $posting->event_key, $reason);
    }

    /**
     * @return array{account:Account,debit:string,credit:string,memo:?string,cost_center_id:?int}
     */
    private function line(
        string $code,
        string $name,
        string $type,
        string $debit,
        string $credit,
        ?string $memo = null,
        ?int $costCenterId = null,
    ): array {
        $account = Account::firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'type' => $type, 'is_active' => true],
        );

        if ($account->type !== $type) {
            throw new RuntimeException("Reserved accounting code {$code} has an incompatible account type.");
        }

        return [
            'account' => $account,
            'debit' => Decimal::normalize($debit),
            'credit' => Decimal::normalize($credit),
            'memo' => $memo,
            'cost_center_id' => $costCenterId,
        ];
    }

    /**
     * @return array{code:string,name:string}
     */
    private function paymentAccount(string $method): array
    {
        return match ($method) {
            'cash' => ['code' => 'AUTO-CASH', 'name' => 'Cash'],
            default => ['code' => 'AUTO-BANK', 'name' => 'Bank & Payment Clearing'],
        };
    }
}
