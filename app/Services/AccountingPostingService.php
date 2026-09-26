<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\AccountingPosting;
use App\Models\Expense;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
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
            $this->line('1100', 'Accounts Receivable', 'asset', $baseTotal, '0', $invoice->invoice_number),
            $this->line('4000', 'Sales Revenue', 'income', '0', $baseRevenue, $invoice->invoice_number),
        ];

        if (! Decimal::isZero($baseTax)) {
            $lines[] = $this->line('2100', 'Tax Payable', 'liability', '0', $baseTax, $invoice->invoice_number);
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
                    $this->line('2000', 'Accounts Payable', 'liability', $amount, '0', $payment->payment_number),
                    $this->line($cash['code'], $cash['name'], 'asset', '0', $amount, $payment->payment_number),
                ],
            );
        }

        return $this->post(
            Payment::class,
            $payment->id,
            'customer-payment',
            'AUTO-'.$payment->payment_number,
            $payment->payment_date->toDateString(),
            'Customer payment '.$payment->payment_number,
            [
                $this->line($cash['code'], $cash['name'], 'asset', $amount, '0', $payment->payment_number),
                $this->line('1100', 'Accounts Receivable', 'asset', '0', $amount, $payment->payment_number),
            ],
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
                $this->line('1200', 'Inventory', 'asset', $amount, '0', $order->number),
                $this->line('2000', 'Accounts Payable', 'liability', '0', $amount, $order->number),
            ],
        );
    }

    public function postPurchaseReturn(InventoryReturn $return): ?JournalEntry
    {
        if ($return->type !== 'purchase') {
            return null;
        }

        $amount = Decimal::normalize((string) $return->total);

        return $this->post(
            InventoryReturn::class,
            $return->id,
            'purchase-return',
            'AUTO-'.$return->number,
            $return->processed_at->toDateString(),
            'Purchase return '.$return->number,
            [
                $this->line('2000', 'Accounts Payable', 'liability', $amount, '0', $return->number),
                $this->line('1200', 'Inventory', 'asset', '0', $amount, $return->number),
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
                $this->line('3990', 'Opening Balance Equity', 'equity', $amount, '0', $supplier->code),
                $this->line('2000', 'Accounts Payable', 'liability', '0', $amount, $supplier->code),
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
                $this->line('6000', 'General Expenses', 'expense', $amount, '0', $expense->expense_number),
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

            $reversal = JournalEntry::create([
                'number' => $number,
                'entry_date' => now()->toDateString(),
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
     * @return array{account:Account,debit:string,credit:string,memo:?string}
     */
    private function line(
        string $code,
        string $name,
        string $type,
        string $debit,
        string $credit,
        ?string $memo = null,
    ): array {
        return [
            'account' => Account::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'type' => $type, 'is_active' => true],
            ),
            'debit' => Decimal::normalize($debit),
            'credit' => Decimal::normalize($credit),
            'memo' => $memo,
        ];
    }

    /**
     * @return array{code:string,name:string}
     */
    private function paymentAccount(string $method): array
    {
        return match ($method) {
            'cash' => ['code' => '1000', 'name' => 'Cash'],
            default => ['code' => '1010', 'name' => 'Bank & Payment Clearing'],
        };
    }
}
