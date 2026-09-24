<?php

namespace App\Support;

use InvalidArgumentException;
use RuntimeException;

/**
 * Exact decimal arithmetic via BCMath — the app's permanent money convention.
 *
 * All money, price, quantity, and rate arithmetic in BusinessOS goes through
 * this single helper so every module rounds identically: 4 decimal places,
 * half-away-from-zero, with 8-decimal intermediate precision. Values are
 * always returned as decimal strings (e.g. "12.3454") so they store cleanly
 * into DECIMAL(16,4) / DECIMAL(8,4) columns and never pass through FLOAT.
 *
 * BCMath (php-bcmath) is present in essentially every standard PHP build; if
 * it is missing the helper refuses loudly at first use instead of silently
 * degrading to float arithmetic, which would violate the exact-money decision.
 *
 * Batch 15 Invoices and later finance modules reuse this helper — the
 * per-document calculators compose it, they never inherit from one another.
 */
final class Decimal
{
    public const MONEY_SCALE = 4;

    public const INTERNAL_SCALE = 8;

    /**
     * Round half-away-from-zero at the given scale.
     *
     * 4-dp examples: "1.23445" -> "1.2345", "1.23444" -> "1.2344",
     * "-1.23445" -> "-1.2345". Scale must be >= 0.
     */
    public static function round(string $value, int $scale = self::MONEY_SCALE): string
    {
        self::assertBcmath();
        self::assertNumeric($value);

        if ($scale < 0) {
            throw new InvalidArgumentException('Decimal scale must be >= 0.');
        }

        $result = bcadd($value, '0', $scale);

        if ($scale === 0) {
            return $result;
        }

        // bcadd truncates toward zero; detect the dropped half and round away.
        $dropped = bcsub($value, $result, self::INTERNAL_SCALE);

        $increment = '0.'.str_repeat('0', $scale - 1).'1';
        // Half a unit at the result scale: 0.00005 at 4 dp (one more zero than
        // the increment has), never the 10x-larger 0.0005.
        $half = '0.'.str_repeat('0', $scale).'5';
        if (bccomp($dropped, $half, self::INTERNAL_SCALE) >= 0) {
            $result = bcadd($result, $increment, $scale);
        } elseif ($dropped !== '' && $dropped[0] === '-' && bccomp($dropped, '-'.$half, self::INTERNAL_SCALE) <= 0) {
            $result = bcsub($result, $increment, $scale);
        }

        return $result;
    }

    /**
     * Normalise an arbitrary input to a canonical decimal string.
     *
     * Accepts int/float/string; "12", 12, 12.0 and "12.0000" all become
     * "12". Throws on non-numeric input so forged or malformed data cannot
     * silently corrupt a total.
     */
    public static function normalize(mixed $value, int $scale = self::MONEY_SCALE): string
    {
        self::assertBcmath();

        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            $value = (string) $value;
        } elseif ($value === null || $value === '') {
            $value = '0';
        } elseif (is_string($value)) {
            $value = trim($value);
        } elseif (! is_scalar($value)) {
            throw new InvalidArgumentException('Decimal input must be a scalar.');
        }

        self::assertNumeric($value);

        return bcadd($value, '0', $scale);
    }

    public static function add(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        self::assertBcmath();

        return bcadd(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function sub(string $a, string $b, int $scale = self::MONEY_SCALE): string
    {
        self::assertBcmath();

        return bcsub(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function mul(string $a, string $b, int $scale = self::INTERNAL_SCALE): string
    {
        self::assertBcmath();

        return bcmul(self::normalize($a), self::normalize($b), $scale);
    }

    /**
     * $a * $b / $divisor with intermediate precision, rounded to money scale.
     * Used for tax: subtotal x rate / 100.
     */
    public static function mulDiv(string $a, string $b, string $divisor, int $scale = self::MONEY_SCALE): string
    {
        self::assertBcmath();
        self::assertNumeric($divisor);

        if (bccomp(self::normalize($divisor, self::INTERNAL_SCALE), '0', self::INTERNAL_SCALE) === 0) {
            throw new InvalidArgumentException('Decimal divisor must not be zero.');
        }

        $product = bcmul(self::normalize($a), self::normalize($b), self::INTERNAL_SCALE);

        return self::round(bcdiv($product, self::normalize($divisor, self::INTERNAL_SCALE), self::INTERNAL_SCALE), $scale);
    }

    /** $a * $b / 100 (the % convenience form used for tax and percent discount). */
    public static function percent(string $amount, string $rate, int $scale = self::MONEY_SCALE): string
    {
        return self::mulDiv($amount, $rate, '100', $scale);
    }

    public static function gte(string $a, string $b): bool
    {
        self::assertBcmath();

        return bccomp(self::normalize($a), self::normalize($b), self::INTERNAL_SCALE) >= 0;
    }

    public static function gt(string $a, string $b): bool
    {
        self::assertBcmath();

        return bccomp(self::normalize($a), self::normalize($b), self::INTERNAL_SCALE) > 0;
    }

    public static function lte(string $a, string $b): bool
    {
        self::assertBcmath();

        return bccomp(self::normalize($a), self::normalize($b), self::INTERNAL_SCALE) <= 0;
    }

    public static function lt(string $a, string $b): bool
    {
        self::assertBcmath();

        return bccomp(self::normalize($a), self::normalize($b), self::INTERNAL_SCALE) < 0;
    }

    public static function eq(string $a, string $b): bool
    {
        self::assertBcmath();

        return bccomp(self::normalize($a), self::normalize($b), self::INTERNAL_SCALE) === 0;
    }

    /** Lower bound clamp: never below $min. */
    public static function min(string $value, string $min): string
    {
        return self::gte($value, $min) ? $value : $min;
    }

    /** Upper bound clamp: never above $max. */
    public static function max(string $value, string $max): string
    {
        return self::lte($value, $max) ? $value : $max;
    }

    public static function isZero(string $value): bool
    {
        return self::eq($value, '0');
    }

    private static function assertBcmath(): void
    {
        if (! function_exists('bcadd')) {
            throw new RuntimeException('BCMath is required for exact decimal arithmetic but is not available.');
        }
    }

    private static function assertNumeric(string $value): void
    {
        if ($value !== '' && ! is_numeric($value)) {
            throw new InvalidArgumentException("Non-numeric decimal value: \"{$value}\".");
        }
    }
}
