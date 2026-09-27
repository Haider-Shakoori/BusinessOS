<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountAdjustmentNote;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountAdjustmentNoteService
{
    public function __construct(
        private readonly FiscalPeriodService $periods,
        private readonly PaymentService $payments,
    ) {}

    public function customerCredit(Invoice $invoice, string $amount, string $date, ?string $reason, int $userId): AccountAdjustmentNote
    {
        return DB::transaction(function () use ($invoice, $amount, $date, $reason, $userId): AccountAdjustmentNote {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $amount = Decimal::normalize($amount);
            if (Decimal::gt($amount, (string) $locked->amount_due)) {
                throw ValidationException::withMessages(['amount' => 'Credit note cannot exceed the invoice outstanding balance.']);
            }

            $this->periods->assertPostingAllowed($date);
            $base = Decimal::round(Decimal::mul($amount, (string) ($locked->exchange_rate ?? '1')));
            $note = AccountAdjustmentNote::create([
                'type' => 'customer_credit',
                'invoice_id' => $locked->id,
                'note_date' => $date,
                'amount' => $amount,
                'base_amount' => $base,
                'currency_code' => $locked->currency_code,
                'reason' => $reason,
                'created_by' => $userId,
            ]);
            $note->forceFill(['number' => 'CN-'.str_pad((string) $note->id, 8, '0', STR_PAD_LEFT)])->save();

            $entry = $this->journal($note, [
                ['AUTO-SALES-RETURNS', 'Sales Returns & Allowances', 'expense', $base, '0'],
                ['AUTO-AR', 'Accounts Receivable', 'asset', '0', $base],
            ]);
            $note->forceFill(['journal_entry_id' => $entry->id])->save();
            $this->payments->reconcileBalance($locked);

            return $note->fresh('journalEntry');
        });
    }

    public function supplierDebit(PurchaseOrder $order, string $amount, string $date, ?string $reason, int $userId): AccountAdjustmentNote
    {
        return DB::transaction(function () use ($order, $amount, $date, $reason, $userId): AccountAdjustmentNote {
            $locked = PurchaseOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($locked->status !== 'received') {
                throw ValidationException::withMessages(['purchase_order_id' => 'Only received purchase orders can receive a debit note.']);
            }

            $amount = Decimal::normalize($amount);
            $existing = Decimal::normalize((string) AccountAdjustmentNote::query()
                ->where('type', 'supplier_debit')->where('purchase_order_id', $locked->id)->where('status', 'posted')->sum('base_amount'));
            if (Decimal::gt(Decimal::add($existing, $amount), (string) $locked->total)) {
                throw ValidationException::withMessages(['amount' => 'Debit notes cannot exceed the purchase order total.']);
            }

            $this->periods->assertPostingAllowed($date);
            $note = AccountAdjustmentNote::create([
                'type' => 'supplier_debit',
                'purchase_order_id' => $locked->id,
                'note_date' => $date,
                'amount' => $amount,
                'base_amount' => $amount,
                'currency_code' => app(CurrencyService::class)->baseCurrency(),
                'reason' => $reason,
                'created_by' => $userId,
            ]);
            $note->forceFill(['number' => 'DN-'.str_pad((string) $note->id, 8, '0', STR_PAD_LEFT)])->save();

            $entry = $this->journal($note, [
                ['AUTO-AP', 'Accounts Payable', 'liability', $amount, '0'],
                ['AUTO-PURCHASE-ADJ', 'Purchase Adjustments', 'income', '0', $amount],
            ]);
            $note->forceFill(['journal_entry_id' => $entry->id])->save();

            return $note->fresh('journalEntry');
        });
    }

    private function journal(AccountAdjustmentNote $note, array $lines): JournalEntry
    {
        $entry = JournalEntry::create([
            'number' => 'AUTO-'.$note->number,
            'entry_date' => $note->note_date,
            'status' => 'posted',
            'description' => $note->number.' '.$note->reason,
            'source_type' => AccountAdjustmentNote::class,
            'source_id' => $note->id,
        ]);

        foreach ($lines as [$code, $name, $type, $debit, $credit]) {
            $account = Account::firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'is_active' => true]);
            $entry->lines()->create(['account_id' => $account->id, 'debit' => $debit, 'credit' => $credit, 'memo' => $note->number]);
        }

        return $entry;
    }
}
