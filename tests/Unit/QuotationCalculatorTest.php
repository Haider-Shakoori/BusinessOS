<?php

namespace Tests\Unit;

use App\Services\QuotationCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Exact quotation arithmetic (Batch 14).
 *
 * The calculator is pure PURE: stateless, DB-free, request-free — every test
 * here feeds decimal STRINGS and asserts the exact 4-dp results, pinning the
 * App\Support\Decimal BCMath conventions (half-away-from-zero at 4 dp,
 * 8-dp intermediates). Lines round individually before summation, tax is
 * exclusive, discounts clamp to the subtotal, and the total is
 * subtotal - discount + tax.
 */
class QuotationCalculatorTest extends TestCase
{
    private function calculator(): QuotationCalculator
    {
        return new QuotationCalculator;
    }

    public function test_empty_lines_produce_zero_document_amounts(): void
    {
        $result = $this->calculator()->calculate([], null, '0');

        $this->assertSame([], $result['items']);
        $this->assertSame('0.0000', $result['subtotal']);
        $this->assertSame('0.0000', $result['tax_amount']);
        $this->assertSame('0.0000', $result['discount_applied']);
        $this->assertSame('0.0000', $result['total']);
    }

    public function test_single_tax_free_line_matches_exact_decimal_multiplication(): void
    {
        $result = $this->calculator()->calculate(
            [['quantity' => '2', 'unit_price' => '25.5000', 'tax_rate' => null]],
            null,
            '0',
        );

        $this->assertSame('51.0000', $result['subtotal']);
        $this->assertSame('0.0000', $result['tax_amount']);
        $this->assertSame('51.0000', $result['total']);
        $this->assertSame('51.0000', $result['items'][0]['line_subtotal']);
        $this->assertSame('0.0000', $result['items'][0]['line_tax']);
        $this->assertSame('51.0000', $result['items'][0]['line_total']);
    }

    public function test_multiple_lines_sum_exactly_at_four_decimal_places(): void
    {
        $result = $this->calculator()->calculate(
            [
                ['quantity' => '2', 'unit_price' => '33.3333', 'tax_rate' => null],
                ['quantity' => '1', 'unit_price' => '50.0000', 'tax_rate' => null],
            ],
            null,
            '0',
        );

        $this->assertSame('66.6666', $result['items'][0]['line_subtotal']);
        $this->assertSame('50.0000', $result['items'][1]['line_subtotal']);
        $this->assertSame('116.6666', $result['subtotal']);
        $this->assertSame('116.6666', $result['total']);
    }

    public function test_repeating_decimal_lines_stay_exact_and_do_not_drift(): void
    {
        // 0.3333 x 3 must be 0.9999, never a float artifact like 1.0000.
        $result = $this->calculator()->calculate(
            [
                ['quantity' => '1', 'unit_price' => '0.3333', 'tax_rate' => null],
                ['quantity' => '1', 'unit_price' => '0.3333', 'tax_rate' => null],
                ['quantity' => '1', 'unit_price' => '0.3333', 'tax_rate' => null],
            ],
            null,
            '0',
        );

        $this->assertSame('0.9999', $result['subtotal']);
        $this->assertSame('0.9999', $result['total']);
    }

    public function test_line_subtotal_rounds_half_away_from_zero(): void
    {
        // 0.5 x 4.0001 = 2.00005 -> half at the 5th digit rounds away -> 2.0001.
        $roundUp = $this->calculator()->calculate(
            [['quantity' => '0.5', 'unit_price' => '4.0001', 'tax_rate' => null]],
            null,
            '0',
        );
        $this->assertSame('2.0001', $roundUp['items'][0]['line_subtotal']);

        // 1.0001 x 1.0001 = 1.00020001 -> below half -> truncate to 1.0002.
        $roundDown = $this->calculator()->calculate(
            [['quantity' => '1.0001', 'unit_price' => '1.0001', 'tax_rate' => null]],
            null,
            '0',
        );
        $this->assertSame('1.0002', $roundDown['items'][0]['line_subtotal']);
    }

    public function test_tax_is_exclusive_and_snapshotted_per_line(): void
    {
        $result = $this->calculator()->calculate(
            [
                ['quantity' => '1', 'unit_price' => '100.0000', 'tax_rate' => '20.0000'],
                ['quantity' => '2', 'unit_price' => '25.0000', 'tax_rate' => '20.0000'],
            ],
            null,
            '0',
        );

        $this->assertSame('100.0000', $result['items'][0]['line_subtotal']);
        $this->assertSame('20.0000', $result['items'][0]['line_tax']);
        $this->assertSame('120.0000', $result['items'][0]['line_total']);
        $this->assertSame('50.0000', $result['items'][1]['line_subtotal']);
        $this->assertSame('10.0000', $result['items'][1]['line_tax']);

        $this->assertSame('150.0000', $result['subtotal']);
        $this->assertSame('30.0000', $result['tax_amount']);
        $this->assertSame('180.0000', $result['total']);
    }

    public function test_tax_rate_rounding_is_exact(): void
    {
        // 1.23455% of 1.0000 cents -> 0.12345500 -> half away-from-zero -> 0.1235.
        $result = $this->calculator()->calculate(
            [['quantity' => '1', 'unit_price' => '1.0000', 'tax_rate' => '12.3455']],
            null,
            '0',
        );

        $this->assertSame('0.1235', $result['items'][0]['line_tax']);
        $this->assertSame('1.1235', $result['items'][0]['line_total']);
    }

    public function test_percentage_discount_is_calculated_off_the_subtotal(): void
    {
        $result = $this->calculator()->calculate(
            [['quantity' => '2', 'unit_price' => '50.0000', 'tax_rate' => null]],
            'percentage',
            '10.0000',
        );

        // 100 subtotal, 10% -> 10 discount -> 90 total.
        $this->assertSame('100.0000', $result['subtotal']);
        $this->assertSame('10.0000', $result['discount_applied']);
        $this->assertSame('90.0000', $result['total']);
    }

    public function test_percentage_discount_rounds_half_away_from_zero(): void
    {
        $result = $this->calculator()->calculate(
            [['quantity' => '1', 'unit_price' => '99.9999', 'tax_rate' => null]],
            'percentage',
            '33.3333',
        );

        // 99.9999 x 33.3333 / 100 = 33.3332666... -> 33.3333 discount.
        $this->assertSame('33.3333', $result['discount_applied']);
        $this->assertSame('66.6666', $result['total']);
    }

    public function test_fixed_discount_is_a_flat_amount(): void
    {
        $result = $this->calculator()->calculate(
            [['quantity' => '3', 'unit_price' => '40.0000', 'tax_rate' => null]],
            'fixed',
            '25.0000',
        );

        $this->assertSame('120.0000', $result['subtotal']);
        $this->assertSame('25.0000', $result['discount_applied']);
        $this->assertSame('95.0000', $result['total']);
    }

    public function test_discounts_never_make_a_total_negative(): void
    {
        $fixedOvershoot = $this->calculator()->calculate(
            [['quantity' => '1', 'unit_price' => '10.0000', 'tax_rate' => null]],
            'fixed',
            '50.0000',
        );
        $this->assertSame('10.0000', $fixedOvershoot['discount_applied']);
        $this->assertSame('0.0000', $fixedOvershoot['total']);

        $percentageOvershoot = $this->calculator()->calculate(
            [['quantity' => '1', 'unit_price' => '10.0000', 'tax_rate' => null]],
            'percentage',
            '200.0000',
        );
        $this->assertSame('10.0000', $percentageOvershoot['discount_applied']);
        $this->assertSame('0.0000', $percentageOvershoot['total']);
    }

    public function test_discount_and_exclusive_tax_combine_in_the_total(): void
    {
        $result = $this->calculator()->calculate(
            [
                ['quantity' => '1', 'unit_price' => '100.0000', 'tax_rate' => '20.0000'],
            ],
            'percentage',
            '10.0000',
        );

        // subtotal 100 - discount 10 = 90, then + tax 20 = 110.
        $this->assertSame('100.0000', $result['subtotal']);
        $this->assertSame('20.0000', $result['tax_amount']);
        $this->assertSame('10.0000', $result['discount_applied']);
        $this->assertSame('110.0000', $result['total']);
    }

    public function test_discount_requires_a_matching_type(): void
    {
        // A positive amount with no/unknown type is never applied.
        $noType = $this->calculator()->calculate(
            [['quantity' => '1', 'unit_price' => '100.0000', 'tax_rate' => null]],
            null,
            '10.0000',
        );
        $this->assertSame('0.0000', $noType['discount_applied']);
        $this->assertSame('100.0000', $noType['total']);

        $unknownType = $this->calculator()->calculate(
            [['quantity' => '1', 'unit_price' => '100.0000', 'tax_rate' => null]],
            'bogus',
            '10.0000',
        );
        $this->assertSame('0.0000', $unknownType['discount_applied']);
        $this->assertSame('100.0000', $unknownType['total']);
    }

    public function test_zero_discount_amount_is_a_no_op(): void
    {
        $result = $this->calculator()->calculate(
            [['quantity' => '1', 'unit_price' => '100.0000', 'tax_rate' => null]],
            'percentage',
            '0.0000',
        );

        $this->assertSame('0.0000', $result['discount_applied']);
        $this->assertSame('100.0000', $result['total']);
    }

    public function test_large_amounts_never_lose_precision(): void
    {
        $result = $this->calculator()->calculate(
            [['quantity' => '123456.7890', 'unit_price' => '999999.9999', 'tax_rate' => null]],
            null,
            '0',
        );

        // 123456.789 x 999999.9999 = 123456788987.6543211 -> rounds to 4 dp.
        $this->assertSame('123456788987.6543', $result['subtotal']);
    }
}
