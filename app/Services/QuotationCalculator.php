<?php

namespace App\Services;

use App\Support\Decimal;

/**
 * Pure quotation arithmetic (Batch 14).
 *
 * Stateless, DB- and request-free: everything is computed from validated raw
 * values and returned as 4-dp decimal strings. The service layer (Batch 14)
 * resolves product/tax references BEFORE calling the calculator, so the
 * calculator never needs to query. Batch 15 Invoices can reuse the same
 * line/tax/total arithmetic by composing this class.
 *
 * Order of operations (documented decision, identical for every document):
 *   line_subtotal = round4(quantity x unit_price)
 *   line_tax      = round4(line_subtotal x tax_rate / 100)
 *   line_total    = line_subtotal + line_tax
 *   subtotal      = Σ line_subtotal
 *   tax_amount    = Σ line_tax
 *   discount      = percentage: round4(subtotal x amount / 100)
 *                | fixed:        min(amount, subtotal)
 *                (both clamped so a percentage/fixed discount never exceeds
 *                  the subtotal; the resolved value is `discount_applied`)
 *   total         = round4(subtotal - discount_applied + tax_amount)
 *
 * Lines are rounded to money scale INDIVIDUALLY before summation so sums are
 * exact 4-dp additions; no float ever enters a stored column.
 */
class QuotationCalculator
{
    /**
     * @param  list<array{quantity: string, unit_price: string, tax_rate: string|null}>  $lines
     * @return array{
     *     items: list<array{line_subtotal: string, line_tax: string, line_total: string}>,
     *     subtotal: string,
     *     tax_amount: string,
     *     discount_applied: string,
     *     total: string,
     * }
     */
    public function calculate(array $lines, ?string $discountType, string $discountAmount): array
    {
        $items = [];
        $subtotal = '0.0000';
        $taxAmount = '0.0000';

        foreach ($lines as $line) {
            $lineSubtotal = Decimal::round(Decimal::mul($line['quantity'], $line['unit_price']));
            $rate = $line['tax_rate'] ?? null;
            $lineTax = $rate !== null
                ? Decimal::round(Decimal::percent($lineSubtotal, $rate))
                : '0.0000';
            $lineTotal = Decimal::add($lineSubtotal, $lineTax);

            $subtotal = Decimal::add($subtotal, $lineSubtotal);
            $taxAmount = Decimal::add($taxAmount, $lineTax);

            $items[] = [
                'line_subtotal' => $lineSubtotal,
                'line_tax' => $lineTax,
                'line_total' => $lineTotal,
            ];
        }

        $discountApplied = $this->discountApplied($subtotal, $discountType, $discountAmount);
        $total = Decimal::round(Decimal::add(Decimal::sub($subtotal, $discountApplied), $taxAmount));

        return [
            'items' => $items,
            'subtotal' => Decimal::normalize($subtotal),
            'tax_amount' => $taxAmount,
            'discount_applied' => $discountApplied,
            'total' => $total,
        ];
    }

    /**
     * The actual discount deducted from the totals, clamped to the subtotal.
     * discount_type accepts 'percentage'|'fixed'|null; anything else is
     * treated as no discount.
     */
    private function discountApplied(string $subtotal, ?string $discountType, string $discountAmount): string
    {
        $amount = Decimal::normalize($discountAmount ?? '0.0000');

        if (Decimal::lte($amount, '0.0000')) {
            return '0.0000';
        }

        $applied = match ($discountType) {
            'percentage' => Decimal::round(Decimal::percent($subtotal, $amount)),
            'fixed' => $amount,
            default => '0.0000',
        };

        // Cap the resolved discount at the subtotal: a fixed amount or a
        // percentage that would exceed the subtotal can never produce a
        // negative charge. Decimal::max is the upper-bound clamp (returns the
        // smaller of the two), which is exactly the subtotal cap we need.
        return Decimal::max($applied, $subtotal);
    }
}
