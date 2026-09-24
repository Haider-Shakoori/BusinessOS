<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Models\Business;
use App\Models\BusinessCurrency;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Setting;
use App\Support\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Batch 19 — currency resolution, conversion and validation.
 *
 * The single authority for currency rules. BusinessContext provides the tenant
 * (its current business); the business base currency comes from the
 * regional.currency setting (BusinessSettings), defaulting to AFN. Everything
 * foreign-currency flows through here — never bespoke logic in the services:
 *
 * - enabledCurrencies() / allActive()  → pickers + enabled-code validation
 * - resolveRate() / resolveOrFail()    → the rate in effect at a document date
 * - toBase()                           → exact DECIMAL(16,4) base conversion
 * - hasFinancialHistory()              → the base-currency change gate
 *
 * Snapshot semantics: a document records the transaction currency, the rate
 * resolved for ITS date, and the resulting base_amount. Later rate edits never
 * rewrite history — they only affect documents still being drafted (whose
 * totals are recomputed at save time anyway).
 */
class CurrencyService
{
    /**
     * Exchange-rate precision: stored and normalised to 8 dp so exact 4-dp
     * base conversions never round a meaningful rate away.
     */
    public const RATE_SCALE = 8;

    public function __construct(
        private readonly BusinessContext $context,
        private readonly BusinessSettings $settings,
    ) {
        //
    }

    /**
     * The current business's base currency code.
     */
    public function baseCurrency(?Business $business = null): string
    {
        if ($business !== null && ! $this->context->isCurrent($business)) {
            // The base currency setting belongs to the business's own settings
            // rows; resolve it directly when the target is not the current one.
            return Setting::query()
                ->where('business_id', $business->id)
                ->where('group', 'regional')
                ->where('key', 'currency')
                ->value('value') ?? config('settings.definitions.regional.currency.default', 'AFN');
        }

        return (string) $this->settings->get('regional.currency', config('settings.definitions.regional.currency.default', 'AFN'));
    }

    /**
     * The currencies usable on documents right now: the base currency plus
     * every explicitly enabled active currency of the current business.
     *
     * @return Collection<int, Currency>
     */
    public function enabledCurrencies(): Collection
    {
        $base = $this->baseCurrency();

        $enabled = BusinessCurrency::query()
            ->where('business_id', $this->context->currentId())
            ->with('currency')
            ->whereHas('currency', fn ($q) => $q->active())
            ->get()
            ->pluck('currency')
            ->keyBy('code');

        $all = Currency::query()->active()->get()->keyBy('code');

        $codes = collect([$base])
            ->merge($enabled->keys())
            ->unique()
            ->filter(fn (string $code): bool => $all->has($code));

        return $codes->map(fn (string $code) => $all->get($code))->values();
    }

    /**
     * The codes a form may legally select (for Rule::in validation).
     *
     * @return list<string>
     */
    public function enabledCodes(): array
    {
        return $this->enabledCurrencies()->pluck('code')->values()->all();
    }

    /**
     * Every ACTIVE currency in the shared registry (for enabling in settings).
     *
     * @return Collection<int, Currency>
     */
    public function allActive(): Collection
    {
        return Currency::query()->active()->orderBy('code')->get();
    }

    /**
     * Whether the code is a legal selection for the current business.
     */
    public function isEnabled(string $currencyCode): bool
    {
        return in_array($currencyCode, $this->enabledCodes(), true);
    }

    /**
     * The currency row for a code, or null.
     */
    public function currency(string $code): ?Currency
    {
        return Currency::query()->where('code', strtoupper($code))->first();
    }

    /**
     * The exchange rate in effect at (or just before, never after) the date.
     *
     * Base currency always returns '1'. A foreign currency returns the newest
     * enabled-rate branch with effective_date <= the as-of date (the document
     * date), or null when no such branch exists — a missing old rate must not
     * silently use a future one.
     *
     * @param  string  $currencyCode  transaction (source) currency
     * @param  string|null  $asOfDate  Y-m-d business date; today when null
     */
    public function resolveRate(string $currencyCode, ?string $asOfDate = null): ?string
    {
        if ($currencyCode === $this->baseCurrency()) {
            return '1';
        }

        $asOf = $asOfDate ?? now()->toDateString();

        $branch = ExchangeRate::query()
            ->where('currency_code', $currencyCode)
            ->whereDate('effective_date', '<=', $asOf)
            ->orderByDesc('effective_date')
            ->orderByDesc('id')
            ->value('rate');

        return $branch === null ? null : Decimal::normalize((string) $branch, self::RATE_SCALE);
    }

    /**
     * resolveRate(), but a missing foreign-currency branch becomes a clean
     * validation error instead of a silent null (documents must never be
     * recorded without a priced rate).
     *
     * @throws ValidationException when the code is not
     *                             the base currency and
     *                             has no rate at the date
     */
    public function resolveOrFail(string $currencyCode, ?string $asOfDate = null): string
    {
        $rate = $this->resolveRate($currencyCode, $asOfDate);

        if ($rate === null) {
            throw ValidationException::withMessages([
                'currency_code' => __('currencies.validation.rate_missing', [
                    'currency' => $currencyCode,
                    'date' => $asOfDate ?? now()->toDateString(),
                ]),
            ]);
        }

        return $rate;
    }

    /**
     * Convert a transaction-currency amount to the business base currency with
     * exact DECIMAL(16,4) rounding (half-away-from-zero, 4 dp convention).
     */
    public function toBase(string $amount, string $rate): string
    {
        return Decimal::round(Decimal::mul($amount, $rate));
    }

    /**
     * Whether the business already has any financial history that pins its
     * base currency: finalized invoices, payments, expenses, or non-draft
     * quotations. Drafts and quotations still in draft do NOT block a change
     * — they can be repriced before anything is final.
     */
    public function hasFinancialHistory(?Business $business = null): bool
    {
        $business ??= Business::current();

        if ($business === null) {
            return false;
        }

        return Invoice::query()
            ->withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->where('status', '!=', InvoiceStatus::Draft->value)
            ->exists()
            || Payment::query()
                ->withoutGlobalScope('business')
                ->where('business_id', $business->id)
                ->exists()
            || Expense::query()
                ->withoutGlobalScope('business')
                ->where('business_id', $business->id)
                ->exists()
            || Quotation::query()
                ->withoutGlobalScope('business')
                ->where('business_id', $business->id)
                ->where('status', '!=', QuotationStatus::Draft->value)
                ->exists();
    }
}
