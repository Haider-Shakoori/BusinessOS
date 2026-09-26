<?php

namespace App\Services;

use App\Models\InventoryReturn;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\Decimal;

final class SupplierLedgerService
{
    public function openingBalance(Supplier $supplier): string
    {
        return Decimal::normalize((string) ($supplier->opening_balance ?? '0'));
    }

    public function totalPurchased(Supplier $supplier): string
    {
        return Decimal::normalize((string) $supplier->purchaseOrders()
            ->where('status', 'received')
            ->sum('total'));
    }

    public function totalReturned(Supplier $supplier): string
    {
        $orderIds = $supplier->purchaseOrders()->pluck('id');

        if ($orderIds->isEmpty()) {
            return '0.0000';
        }

        return Decimal::normalize((string) InventoryReturn::query()
            ->where('type', 'purchase')
            ->where('status', 'completed')
            ->where('source_type', PurchaseOrder::class)
            ->whereIn('source_id', $orderIds)
            ->sum('total'));
    }

    public function totalPaid(Supplier $supplier): string
    {
        return Decimal::normalize((string) Payment::query()
            ->where('party_type', 'supplier')
            ->where('party_id', $supplier->id)
            ->whereNull('reversed_at')
            ->sum('base_amount'));
    }

    public function outstandingBalance(Supplier $supplier): string
    {
        return Decimal::sub(
            Decimal::sub(
                Decimal::add($this->openingBalance($supplier), $this->totalPurchased($supplier)),
                $this->totalReturned($supplier),
            ),
            $this->totalPaid($supplier),
        );
    }

    /**
     * @return array{opening_balance:string,total_purchased:string,total_returned:string,total_paid:string,outstanding_balance:string}
     */
    public function summary(Supplier $supplier): array
    {
        $opening = $this->openingBalance($supplier);
        $purchased = $this->totalPurchased($supplier);
        $returned = $this->totalReturned($supplier);
        $paid = $this->totalPaid($supplier);

        return [
            'opening_balance' => $opening,
            'total_purchased' => $purchased,
            'total_returned' => $returned,
            'total_paid' => $paid,
            'outstanding_balance' => Decimal::sub(Decimal::sub(Decimal::add($opening, $purchased), $returned), $paid),
        ];
    }

    /**
     * Supplier balance is a payable: credits increase what the business owes,
     * while debits (returns/payments) reduce it.
     *
     * @param  array{search?:string|null,type?:string|null,date_from?:string|null,date_to?:string|null}  $filters
     * @return array{rows:list<array<string,mixed>>,closing_balance:string,show_running_balance:bool}
     */
    public function ledger(Supplier $supplier, array $filters = []): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $type = $filters['type'] ?? null;
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $entries = $this->rawEntries($supplier);

        if ($search !== '') {
            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => stripos((string) $entry['reference'], $search) !== false
                    || stripos((string) ($entry['description'] ?? ''), $search) !== false,
            ));
        }

        if ($type !== null) {
            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => $entry['type'] === $type
                    || ($type === 'payment' && $entry['type'] === 'reversal'),
            ));
        }

        $rowLevelFilter = $search !== '' || $type !== null;
        $balance = '0.0000';
        $rows = [];

        foreach ($entries as $entry) {
            if ($dateFrom !== null && $entry['date'] !== null && $entry['date'] < $dateFrom) {
                continue;
            }

            if ($dateTo !== null && $entry['date'] !== null && $entry['date'] > $dateTo) {
                continue;
            }

            $balance = Decimal::add($balance, Decimal::sub($entry['credit'], $entry['debit']));
            $entry['balance'] = $balance;
            $rows[] = $entry;
        }

        return [
            'rows' => $rows,
            'closing_balance' => $balance,
            'show_running_balance' => ! $rowLevelFilter,
        ];
    }

    /**
     * @return list<array{date:?string,type:string,reference:string,description:?string,debit:string,credit:string,balance:string,reversed:bool,sort:int}>
     */
    private function rawEntries(Supplier $supplier): array
    {
        $entries = [];
        $sort = 0;
        $opening = $this->openingBalance($supplier);

        $entries[] = [
            'date' => $supplier->opening_balance_date?->format('Y-m-d'),
            'type' => 'opening',
            'reference' => '',
            'description' => null,
            'debit' => '0.0000',
            'credit' => $opening,
            'balance' => '0.0000',
            'reversed' => false,
            'sort' => $sort++,
        ];

        $orders = $supplier->purchaseOrders()
            ->where('status', 'received')
            ->orderBy('order_date')
            ->orderBy('id')
            ->get(['id', 'number', 'order_date', 'total', 'notes']);

        foreach ($orders as $order) {
            $entries[] = [
                'date' => $order->order_date?->format('Y-m-d'),
                'type' => 'purchase',
                'reference' => (string) $order->number,
                'description' => $order->notes,
                'debit' => '0.0000',
                'credit' => Decimal::normalize((string) $order->total),
                'balance' => '0.0000',
                'reversed' => false,
                'sort' => $sort++,
            ];
        }

        $orderIds = $orders->pluck('id');

        if ($orderIds->isNotEmpty()) {
            foreach (InventoryReturn::query()
                ->where('type', 'purchase')
                ->where('status', 'completed')
                ->where('source_type', PurchaseOrder::class)
                ->whereIn('source_id', $orderIds)
                ->orderBy('processed_at')
                ->orderBy('id')
                ->get() as $return) {
                $entries[] = [
                    'date' => $return->processed_at?->format('Y-m-d'),
                    'type' => 'return',
                    'reference' => (string) $return->number,
                    'description' => $return->reason,
                    'debit' => Decimal::normalize((string) $return->total),
                    'credit' => '0.0000',
                    'balance' => '0.0000',
                    'reversed' => false,
                    'sort' => $sort++,
                ];
            }
        }

        foreach (Payment::query()
            ->where('party_type', 'supplier')
            ->where('party_id', $supplier->id)
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get() as $payment) {
            $amount = Decimal::normalize((string) ($payment->base_amount ?? $payment->amount));
            $active = $payment->reversed_at === null;

            $entries[] = [
                'date' => $payment->payment_date?->format('Y-m-d'),
                'type' => 'payment',
                'reference' => (string) $payment->payment_number,
                'description' => $payment->reference,
                'debit' => $amount,
                'credit' => '0.0000',
                'balance' => '0.0000',
                'reversed' => ! $active,
                'sort' => $sort++,
            ];

            if (! $active) {
                $entries[] = [
                    'date' => $payment->reversed_at?->format('Y-m-d') ?? $payment->payment_date?->format('Y-m-d'),
                    'type' => 'reversal',
                    'reference' => (string) $payment->payment_number,
                    'description' => $payment->reversal_reason,
                    'debit' => '0.0000',
                    'credit' => $amount,
                    'balance' => '0.0000',
                    'reversed' => false,
                    'sort' => $sort++,
                ];
            }
        }

        usort($entries, function (array $a, array $b): int {
            if ($a['date'] === null && $b['date'] === null) {
                return $a['sort'] <=> $b['sort'];
            }

            if ($a['date'] === null) {
                return -1;
            }

            if ($b['date'] === null) {
                return 1;
            }

            return strcmp($a['date'], $b['date']) ?: ($a['sort'] <=> $b['sort']);
        });

        return $entries;
    }
}
