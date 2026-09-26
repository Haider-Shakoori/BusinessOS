<?php

namespace App\Support;

use App\Services\BusinessSettings;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use NumberFormatter;

final class LocalizedFormatter
{
    public function __construct(private readonly BusinessSettings $settings)
    {
        //
    }

    public function number(int|float|string|null $value, int $decimals = 2): string
    {
        $number = is_numeric($value) ? (float) $value : 0.0;
        $formatter = new NumberFormatter($this->locale(), NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimals);
        $formatter->setAttribute(NumberFormatter::GROUPING_USED, 1);

        $formatted = $formatter->format($number);

        return $formatted !== false ? $formatted : number_format($number, $decimals);
    }

    public function currency(int|float|string|null $value, ?string $currency = null): string
    {
        $code = strtoupper($currency ?: (string) $this->settings->get('regional.currency', 'AFN'));
        $number = is_numeric($value) ? (float) $value : 0.0;
        $formatter = new NumberFormatter($this->locale(), NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 2);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

        $formatted = $formatter->formatCurrency($number, $code);

        return $formatted !== false ? $formatted : $code.' '.number_format($number, 2);
    }

    public function date(CarbonInterface|string|null $value, ?string $format = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $date = $value instanceof CarbonInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value);

        $format ??= (string) $this->settings->get('regional.date_format', 'Y-m-d');

        return $date->locale($this->carbonLocale())->translatedFormat($format);
    }

    public function time(CarbonInterface|string|null $value, ?string $format = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $time = $value instanceof CarbonInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value);

        $format ??= (string) $this->settings->get('regional.time_format', 'H:i');

        return $time->locale($this->carbonLocale())->translatedFormat($format);
    }

    private function locale(): string
    {
        return match (app()->getLocale()) {
            'fa' => 'fa_AF',
            'ar' => 'ar',
            default => 'en_US',
        };
    }

    private function carbonLocale(): string
    {
        return match (app()->getLocale()) {
            'fa' => 'fa',
            'ar' => 'ar',
            default => 'en',
        };
    }
}
