<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Payment;
use App\Support\Decimal;
use Illuminate\Support\Collection;

/**
 * Customer balance and ledger read model (Batch 18).
 *
 * The ledger is a calculated view over the authoritative Batch 15/16 records —
 * invoice totals and active payment allocations — never a new financial table.
 * Amounts are always exact Decimal (BCMath) 4-dp strings; no float arithmetic
 * and no reliance on the reconciled amount_paid/amount_due caches (those are
 * derived, the allocations are the source of truth).
 *
 * Reversal presentation (approved option): a reversed payment appears as its
 * original credit row (clearly marked reversed) plus an explicit reversal
 * debit row, so history is kept and the running balance cancels exactly.
 *
 * Batch 19 (multi-currency): every ledger row keeps its original
 * debit/credit/balance contract PLUS a currency_code and the exact
 * base_debit/base_credit conversions. All aggregates, the running balance and
 * the closing balance are denominated in the business BASE currency (the
 * meaningful cross-currency total), and for the common rate-1 case they are
 * numerically identical to the pre-Batch-19 values. The per-row nominal
 * debit/credit remains the transaction-currency amount with its currency shown.
 *
 * Tenancy comes from the BelongsToBusiness global scope on Customer/Invoice
 * and the business-scoped Payment query; cross-business data never mixes.
 *
 * Deleted handling follows existing conventions: soft-deleted customers resolve
 * as 404 at the route layer (their ledger is not reachable), soft-deleted
 * invoices are excluded by the SoftDeletes scope, and draft invoices never
 * contribute to balances (a draft is not yet an obligation).
 */
final class CustomerLedgerService
{
    public function __construct(private readonly CurrencyService $currencies)
    {
        //
    }

    /** The current business base currency (for display and base conversions). */
    private function baseCurrency(): string
    {
        return $this->currencies->baseCurrency();
    }

    /**
     * The customer's opening balance (money owed at the start), normalized.
     */
    public function openingBalance(Customer $customer): string
    {
        return Decimal::normalize($customer->opening_balance);
    }

    /**
     * Sum of finalized (non-draft) invoice totals expressed in the business
     * BASE currency, from each invoice's permanent base_amount snapshot (the
     * exact conversion recorded at its own date/rate). Drafts never count.
     */
    public function totalInvoiced(Customer $customer): string
    {
        return $customer->invoices()
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->get(['total', 'base_amount', 'exchange_rate'])
            ->reduce(
                fn (string $carry, $invoice): string => Decimal::add(
                    $carry,
                    $this->baseAmount($invoice->base_amount, (string) $invoice->total, $invoice->exchange_rate),
                ),
                '0.0000',
            );
    }

    /**
     * Sum of active (non-reversed) payment allocations applied to the customer,
     * expressed in base currency from each payment's permanent base_amount
     * snapshot (which converts the full amount; the single allocation equals it).
     *
     * Computed from the authoritative allocation records joined through the
     * payment reversal marker — never from a stale UI/cache amount.
     */
    public function totalPaid(Customer $customer): string
    {
        return $this->payments($customer, onlyActive: true)
            ->reduce(function (string $carry, Payment $payment): string {
                foreach ($payment->allocations as $allocation) {
                    $carry = Decimal::add(
                        $carry,
                        $this->baseAmount($payment->base_amount, (string) $allocation->amount, $payment->exchange_rate),
                    );
                }

                return $carry;
            }, '0.0000');
    }

    /**
     * Opening balance + invoiced - paid. A reversed payment therefore increases
     * the outstanding balance again (its credit stops counting).
     */
    public function outstandingBalance(Customer $customer): string
    {
        $base = Decimal::add($this->openingBalance($customer), $this->totalInvoiced($customer));

        return Decimal::sub($base, $this->totalPaid($customer));
    }

    /**
     * The authoritative balance headline used by cards and the show page.
     *
     * @return array{
     *     opening_balance: string,
     *     total_invoiced: string,
     *     total_paid: string,
     *     outstanding_balance: string,
     * }
     */
    public function summary(Customer $customer): array
    {
        $opening = $this->openingBalance($customer);
        $invoiced = $this->totalInvoiced($customer);
        $paid = $this->totalPaid($customer);

        return [
            'opening_balance' => $opening,
            'total_invoiced' => $invoiced,
            'total_paid' => $paid,
            'outstanding_balance' => Decimal::sub(Decimal::add($opening, $invoiced), $paid),
        ];
    }

    /**
     * The chronological ledger. Each row exposes date, type, reference,
     * description, debit, credit and balance (running).
     *
     * Filters:
     *   - date_from / date_to (Y-m-d): when date_from is given with no
     *     row-level filters, a "Balance brought forward" row starts the
     *     statement and the running balance is exact; the closing balance still
     *     equals the customer's total outstanding balance.
     *   - search: row-level filter on the document number (invoice/payment).
     *   - type: 'invoice' or 'payment' (payment includes its reversal rows).
     *
     * Row-level filters (search/type) break running-balance continuity, so
     * showRunningBalance is false for them and the caller hides the balance
     * column rather than display a misleading cumulative figure.
     *
     * @param  array{date_from?: string|null, date_to?: string|null, search?: string|null, type?: string|null}  $filters
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     brought_forward: ?string,
     *     closing_balance: string,
     *     show_running_balance: bool,
     *     has_period_filter: bool,
     * }
     */
    public function ledger(Customer $customer, array $filters = []): array
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $search = trim((string) ($filters['search'] ?? ''));
        $type = $filters['type'] ?? null;

        $entries = $this->rawEntries($customer);

        if ($search !== '') {
            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => stripos((string) $entry['reference'], $search) !== false,
            ));
        }

        if ($type === 'invoice') {
            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => $entry['type'] === 'invoice',
            ));
        } elseif ($type === 'payment') {
            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => in_array($entry['type'], ['payment', 'reversal'], true),
            ));
        }

        $rowLevelFilter = $search !== '' || $type !== null;
        $rows = [];
        $broughtForward = null;
        $balance = '0.0000';

        if ($dateFrom !== null && ! $rowLevelFilter) {
            // Period statement: compute the balance brought forward from every
            // entry dated strictly before the period, then list the period rows.
            $visible = [];
            foreach ($entries as $entry) {
                if ($entry['date'] === null || $entry['date'] < $dateFrom) {
                    $broughtForward = Decimal::add(
                        $broughtForward ?? '0.0000',
                        Decimal::sub($entry['base_debit'], $entry['base_credit']),
                    );
                } elseif ($dateTo === null || $entry['date'] <= $dateTo) {
                    $visible[] = $entry;
                }
            }

            $broughtForward ??= '0.0000';

            $rows[] = [
                'date' => null,
                'type' => 'brought_forward',
                'reference' => '',
                'description' => null,
                'debit' => $broughtForward,
                'credit' => '0.0000',
                'balance' => $broughtForward,
                'currency_code' => $this->baseCurrency(),
                'base_debit' => $broughtForward,
                'base_credit' => '0.0000',
                'reversed' => false,
            ];

            $balance = $broughtForward;
            foreach ($visible as $entry) {
                $balance = Decimal::add($balance, Decimal::sub($entry['base_debit'], $entry['base_credit']));
                $entry['balance'] = $balance;
                $rows[] = $entry;
            }
        } else {
            // Unfiltered full ledger (or row-level filtered subset): the
            // opening entry (nullable date) always starts the list; optional
            // date bounds still filter dated entries.
            foreach ($entries as $entry) {
                $startsPeriod = $entry['date'] === null;
                if ($dateFrom !== null && ! $startsPeriod && $entry['date'] < $dateFrom) {
                    continue;
                }
                if ($dateTo !== null && ! $startsPeriod && $entry['date'] > $dateTo) {
                    continue;
                }

                $balance = Decimal::add($balance, Decimal::sub($entry['base_debit'], $entry['base_credit']));
                $entry['balance'] = $balance;
                $rows[] = $entry;
            }
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
     * Payments recorded against the customer (party_type = 'customer').
     *
     * @return Collection<int, Payment>
     */
    private function payments(Customer $customer, bool $onlyActive = false): Collection
    {
        $query = Payment::query()
            ->where('party_type', 'customer')
            ->where('party_id', $customer->getKey())
            ->with('allocations')
            ->orderBy('payment_date')
            ->orderBy('id');

        if ($onlyActive) {
            $query->whereNull('reversed_at');
        }

        return $query->get();
    }

    /**
     * All ledger entries before any filtering: opening balance (nullable date),
     * one row per finalized invoice, and one row per allocation — a credit for
     * an active payment, a credit plus an explicit reversal debit for a
     * reversed payment. Deterministically sorted by date then build order.
     *
     * debit/credit carry the nominal TRANSACTION-currency amount (balanced by
     * currency_code); base_debit/base_credit carry the exact BASE-currency
     * equivalent that the running balance accumulates.
     *
     * @return list<array{
     *     date: ?string,
     *     type: string,
     *     reference: string,
     *     description: ?string,
     *     debit: string,
     *     credit: string,
     *     balance: string,
     *     currency_code: string,
     *     base_debit: string,
     *     base_credit: string,
     *     reversed: bool,
     *     model_type: ?string,
     *     model_id: ?int,
     *     sort: int,
     * }>
     */
    private function rawEntries(Customer $customer): array
    {
        $entries = [];
        $sort = 0;
        $base = $this->baseCurrency();

        $openingDate = $customer->opening_balance_date?->format('Y-m-d');
        $opening = $this->openingBalance($customer);

        $entries[] = [
            'date' => $openingDate,
            'type' => 'opening',
            'reference' => '',
            'description' => null,
            'debit' => $opening,
            'credit' => '0.0000',
            'balance' => '0.0000',
            'currency_code' => $base,
            'base_debit' => $opening,
            'base_credit' => '0.0000',
            'reversed' => false,
            'model_type' => null,
            'model_id' => null,
            'sort' => $sort++,
        ];

        foreach ($customer->invoices()
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->orderBy('date')
            ->orderBy('id')
            ->get(['id', 'invoice_number', 'date', 'total', 'base_amount', 'exchange_rate', 'currency_code', 'notes']) as $invoice) {
            $entries[] = [
                'date' => $invoice->date?->format('Y-m-d'),
                'type' => 'invoice',
                'reference' => (string) $invoice->invoice_number,
                'description' => $invoice->notes,
                'debit' => (string) $invoice->total,
                'credit' => '0.0000',
                'balance' => '0.0000',
                'currency_code' => $invoice->currency_code ?? $base,
                'base_debit' => $this->baseAmount($invoice->base_amount, (string) $invoice->total, $invoice->exchange_rate),
                'base_credit' => '0.0000',
                'reversed' => false,
                'model_type' => 'invoice',
                'model_id' => (int) $invoice->id,
                'sort' => $sort++,
            ];
        }

        foreach ($this->payments($customer) as $payment) {
            $active = $payment->reversed_at === null;
            $paymentDate = $payment->payment_date?->format('Y-m-d');
            $paymentCurrency = $payment->currency_code ?? $base;
            $paymentBase = $this->baseAmount($payment->base_amount, (string) $payment->amount, $payment->exchange_rate);

            foreach ($payment->allocations as $allocation) {
                $entries[] = [
                    'date' => $paymentDate,
                    'type' => 'payment',
                    'reference' => (string) $payment->payment_number,
                    'description' => $payment->reference,
                    'debit' => '0.0000',
                    'credit' => (string) $allocation->amount,
                    'balance' => '0.0000',
                    'currency_code' => $paymentCurrency,
                    'base_debit' => '0.0000',
                    'base_credit' => $paymentBase,
                    'reversed' => ! $active,
                    'model_type' => 'payment',
                    'model_id' => (int) $payment->id,
                    'sort' => $sort++,
                ];

                if (! $active) {
                    $entries[] = [
                        'date' => $payment->reversed_at?->format('Y-m-d') ?? $paymentDate,
                        'type' => 'reversal',
                        'reference' => (string) $payment->payment_number,
                        'description' => $payment->reversal_reason,
                        'debit' => (string) $allocation->amount,
                        'credit' => '0.0000',
                        'balance' => '0.0000',
                        'currency_code' => $paymentCurrency,
                        'base_debit' => $paymentBase,
                        'base_credit' => '0.0000',
                        'reversed' => false,
                        'model_type' => 'payment',
                        'model_id' => (int) $payment->id,
                        'sort' => $sort++,
                    ];
                }
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

    /**
     * The authoritative base-currency value of a recorded amount: the document's
     * own base_amount snapshot when present, otherwise (legacy rows) the nominal
     * value converted at the document's stored rate (rate 1 → nominal itself).
     */
    private function baseAmount(?string $baseAmount, string $nominal, ?string $rate): string
    {
        if ($baseAmount !== null) {
            return Decimal::normalize($baseAmount);
        }

        if ($rate !== null && $rate !== '1') {
            return Decimal::round(Decimal::mul(Decimal::normalize($nominal), $rate));
        }

        return Decimal::normalize($nominal);
    }
}
