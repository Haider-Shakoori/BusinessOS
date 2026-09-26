<?php

namespace App\Services;

use App\Models\InventoryReturn;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\Decimal;
use Illuminate\Support\Collection;

final class SupplierLedgerService
{
    public function openingBalance(Supplier $supplier): string
    {
        return Decimal::normalize((string) $supplier->opening_balance);
    }

    public function totalPurchases(Supplier $supplier): string
    {
        return $supplier->purchaseOrders()
            ->where('status', 'received')
            ->get(['total'])
            ->reduce(fn (string $carry, PurchaseOrder $order): string => Decimal::add($carry, (string) $order->total), '0.0000');
    }

    public function totalReturns(Supplier $supplier): string
    {
        return InventoryReturn::query()
            ->where('type', 'purchase')
            ->where('status', 'completed')
            ->where('source_type', PurchaseOrder::class)
            ->whereIn('source_id', $supplier->purchaseOrders()->select('id'))
            ->get(['total'])
            ->reduce(fn (string $carry, InventoryReturn $return): string => Decimal::add($carry, (string) $return->total), '0.0000');
    }

    public function totalPaid(Supplier $supplier): string
    {
        return $this->payments($supplier, true)
            ->reduce(fn (string $carry, Payment $payment): string => Decimal::add($carry, (string) ($payment->base_amount ?? $payment->amount)), '0.0000');
    }

    /**
     * @return array{opening_balance:string,total_purchases:string,total_returns:string,total_paid:string,outstanding_balance:string}
     */
    public function summary(Supplier $supplier): array
    {
        $opening = $this->openingBalance($supplier);
        $purchases = $this->totalPurchases($supplier);
        $returns = $this->totalReturns($supplier);
        $paid = $this->totalPaid($supplier);
        $outstanding = Decimal::sub(Decimal::sub(Decimal::add($opening, $purchases), $returns), $paid);

        return [
            'opening_balance' => $opening,
            'total_purchases' => $purchases,
            'total_returns' => $returns,
            'total_paid' => $paid,
            'outstanding_balance' => $outstanding,
        ];
    }

    /**
     * @param array{date_from?:string|null,date_to?:string|null,search?:string|null,type?:string|null} $filters
     * @return array{rows:list<array<string,mixed>>,brought_forward:?string,closing_balance:string,show_running_balance:bool,has_period_filter:bool}
     */
    public function ledger(Supplier $supplier, array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));
        $type = $filters['type'] ?? null;

        $entries = $this->rawEntries($supplier);

        if ($search !== '') {
            $entries = array_values(array_filter($entries, fn (array $entry): bool =>
                stripos((string) $entry['reference'], $search) !== false
                || stripos((string) $entry['description'], $search) !== false
            ));
        }

        if (is_string($type) && $type !== '') {
            $entries = array_values(array_filter($entries, fn (array $entry): bool => $entry['type'] === $type));
        }

        $rowLevelFilter = $search !== '' || ($type !== null && $type !== '');
        $broughtForward = null;
        $balance = '0.0000';
        $rows = [];

        if ($dateFrom !== null && ! $rowLevelFilter) {
            foreach ($entries as $entry) {
                if ($entry['date'] !== null && $entry['date'] < $dateFrom) {
                    $broughtForward = Decimal::add(
                        $broughtForward ?? '0.0000',
                        Decimal::sub($entry['debit'], $entry['credit']),
                    );
                }
            }

            $balance = $broughtForward ?? '0.0000';

            if ($broughtForward !== null) {
                $rows[] = [
                    'date' => null,
                    'type' => 'brought_forward',
                    'reference' => '',
                    'description' => __('suppliers.ledger.brought_forward'),
                    'debit' => $broughtForward,
                    'credit' => '0.0000',
                    'balance' => $balance,
                    'reversed' => false,
                ];
            }
        }

        foreach ($entries as $entry) {
            if ($dateFrom !== null && $entry['date'] !== null && $entry['date'] < $dateFrom) {
                continue;
            }
            if ($dateTo !== null && $entry['date'] !== null && $entry['date'] > $dateTo) {
                continue;
            }

            $balance = Decimal::add($balance, Decimal::sub($entry['debit'], $entry['credit']));
            $entry['balance'] = $balance;
            $rows[] = $entry;
        }

        return [
            'rows' => $rows,
            'brought_forward' => $broughtForward,
            'closing_balance' => $balance,
            'show_running_balance' => ! $rowLevelFilter,
            'has_period_filter' => $dateFrom !== null || $dateTo !== null,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rawEntries(Supplier $supplier): array
    {
        $entries = [];

        if (Decimal::gt($this->openingBalance($supplier), '0')) {
            $entries[] = [
                'date' => $supplier->opening_balance_date?->format('Y-m-d') ?? $supplier->created_at?->toDateString(),
                'type' => 'opening',
                'reference' => '',
                'description' => __('suppliers.ledger.opening_balance'),
                'debit' => $this->openingBalance($supplier),
                'credit' => '0.0000',
                'reversed' => false,
                'model_id' => null,
                'model_type' => null,
            ];
        }

        foreach ($supplier->purchaseOrders()->where('status', 'received')->get() as $order) {
            $entries[] = [
                'date' => $order->order_date?->format('Y-m-d'),
                'type' => 'purchase',
                'reference' => $order->number,
                'description' => __('suppliers.ledger.purchase_received'),
                'debit' => Decimal::normalize((string) $order->total),
                'credit' => '0.0000',
                'reversed' => false,
                'model_id' => $order->id,
                'model_type' => 'purchase',
            ];
        }

        $purchaseIds = $supplier->purchaseOrders()->pluck('id');

        if ($purchaseIds->isNotEmpty()) {
            foreach (InventoryReturn::query()
                ->where('type', 'purchase')
                ->where('status', 'completed')
                ->where('source_type', PurchaseOrder::class)
                ->whereIn('source_id', $purchaseIds)
                ->get() as $return) {
                $entries[] = [
                    'date' => $return->processed_at?->toDateString(),
                    'type' => 'return',
                    'reference' => $return->number,
                    'description' => __('suppliers.ledger.purchase_return'),
                    'debit' => '0.0000',
                    'credit' => Decimal::normalize((string) $return->total),
                    'reversed' => false,
                    'model_id' => $return->id,
                    'model_type' => 'return',
                ];
            }
        }

        foreach ($this->payments($supplier, false) as $payment) {
            $amount = Decimal::normalize((string) ($payment->base_amount ?? $payment->amount));

            $entries[] = [
                'date' => $payment->payment_date?->format('Y-m-d'),
                'type' => 'payment',
                'reference' => $payment->payment_number,
                'description' => $payment->reference ?: __('suppliers.ledger.payment'),
                'debit' => '0.0000',
                'credit' => $amount,
                'reversed' => $payment->reversed_at !== null,
                'model_id' => $payment->id,
                'model_type' => 'payment',
            ];

            if ($payment->reversed_at !== null) {
                $entries[] = [
                    'date' => $payment->reversed_at->toDateString(),
                    'type' => 'reversal',
                    'reference' => $payment->payment_number,
                    'description' => $payment->reversal_reason ?: __('suppliers.ledger.payment_reversal'),
                    'debit' => $amount,
                    'credit' => '0.0000',
                    'reversed' => false,
                    'model_id' => $payment->id,
                    'model_type' => 'payment',
                ];
            }
        }

        usort($entries, function (array $a, array $b): int {
            $date = strcmp((string) ($a['date'] ?? ''), (string) ($b['date'] ?? ''));
            if ($date !== 0) {
                return $date;
            }

            return strcmp((string) $a['reference'], (string) $b['reference']);
        });

        return $entries;
    }

    /**
     * @return Collection<int, Payment>
     */
    private function payments(Supplier $supplier, bool $onlyActive): Collection
    {
        return Payment::query()
            ->where('party_type', 'supplier')
            ->where('party_id', $supplier->id)
            ->when($onlyActive, fn ($query) => $query->whereNull('reversed_at'))
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();
    }
}
