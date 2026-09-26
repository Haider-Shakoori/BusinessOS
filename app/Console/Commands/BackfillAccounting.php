<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\Expense;
use App\Models\InventoryReturn;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\AccountingPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class BackfillAccounting extends Command
{
    protected $signature = 'accounting:backfill {--business= : Limit backfill to one business id}';

    protected $description = 'Create missing accounting postings for existing BusinessOS financial transactions.';

    public function handle(): int
    {
        $query = Business::query()->orderBy('id');

        if ($this->option('business') !== null) {
            $query->whereKey((int) $this->option('business'));
        }

        $processed = 0;

        foreach ($query->get() as $business) {
            $membership = BusinessMembership::query()
                ->with('user')
                ->where('business_id', $business->id)
                ->whereNotNull('user_id')
                ->orderBy('id')
                ->first();

            if ($membership?->user === null) {
                $this->warn("Skipping business {$business->id} ({$business->name}): no user membership.");

                continue;
            }

            Auth::guard('web')->login($membership->user);
            Session::put(config('business.context.session_key'), $business->id);
            app()->forgetScopedInstances();

            /** @var AccountingPostingService $accounting */
            $accounting = app(AccountingPostingService::class);

            Invoice::query()
                ->where('status', '!=', InvoiceStatus::Draft->value)
                ->orderBy('id')
                ->each(fn (Invoice $invoice) => $accounting->postInvoice($invoice));

            Payment::query()
                ->orderBy('id')
                ->each(function (Payment $payment) use ($accounting): void {
                    $accounting->postPayment($payment);

                    if ($payment->reversed_at !== null) {
                        $accounting->reversePayment($payment, $payment->reversal_reason ?: 'Historical payment reversal');
                    }
                });

            Expense::withTrashed()
                ->orderBy('id')
                ->each(function (Expense $expense) use ($accounting): void {
                    $accounting->postExpense($expense);

                    if ($expense->trashed()) {
                        $accounting->reverseExpense($expense);
                    }
                });

            PurchaseOrder::query()
                ->where('status', 'received')
                ->orderBy('id')
                ->each(fn (PurchaseOrder $order) => $accounting->postPurchaseReceipt($order));

            InventoryReturn::query()
                ->where('type', 'purchase')
                ->where('status', 'completed')
                ->orderBy('id')
                ->each(fn (InventoryReturn $return) => $accounting->postPurchaseReturn($return));

            Supplier::withTrashed()
                ->where('opening_balance', '>', 0)
                ->orderBy('id')
                ->each(function (Supplier $supplier) use ($accounting): void {
                    $accounting->postSupplierOpeningBalance($supplier);

                    if ($supplier->trashed()) {
                        $accounting->reverseSupplierOpeningBalance($supplier);
                    }
                });

            $processed++;
            $this->info("Accounting backfill complete for business {$business->id}: {$business->name}");
        }

        Auth::guard('web')->logout();
        Session::forget(config('business.context.session_key'));
        app()->forgetScopedInstances();

        $this->info("Accounting backfill finished for {$processed} business(es).");

        return self::SUCCESS;
    }
}
