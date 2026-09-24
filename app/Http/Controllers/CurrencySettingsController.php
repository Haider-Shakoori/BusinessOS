<?php

namespace App\Http\Controllers;

use App\Http\Requests\Currency\StoreCurrencyRequest;
use App\Http\Requests\Currency\StoreExchangeRateRequest;
use App\Models\BusinessCurrency;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\BusinessContext;
use App\Services\CurrencyService;
use App\Support\Decimal;
use Illuminate\Http\RedirectResponse;

/**
 * Batch 19 — currency management (settings module, settings.manage).
 *
 * Three responsibilities, all scoped to the CURRENT business from
 * BusinessContext (never a request-supplied business_id):
 *
 *  1. enable / disable a currency (business_currencies rows). Disabling never
 *     touches history — every document keeps its own permanent snapshot — it
 *     only removes the code from future pickers. The base currency is always
 *     implicitly enabled and therefore cannot be enabled/disabled here.
 *
 *  2. maintain exchange rates (exchange_rates rows) for truly-enabled foreign
 *     currencies. A rate means "1 unit of this currency costs X units of the
 *     business base currency"; the base currency itself has rate 1 and needs
 *     no row. Each (currency, effective_date) branch is updated in place, so
 *     posting a same-day rate simply corrects today's branch.
 *
 *  3. the regional.currency base-currency select is owned by SettingsController
 *     (it lives on the ordinary settings form next to the regional fields) and
 *     is gated there — see CurrencyService::hasFinancialHistory.
 */
class CurrencySettingsController extends Controller
{
    public function __construct(
        private readonly BusinessContext $context,
        private readonly CurrencyService $currencies,
    ) {
        //
    }

    /**
     * Enable an active currency for the current business.
     */
    public function storeCurrency(StoreCurrencyRequest $request): RedirectResponse
    {
        $code = strtoupper($request->validated('currency_code'));

        if ($code === $this->currencies->baseCurrency()) {
            return back()
                ->withErrors(['currency_code' => __('currencies.validation.is_base')])
                ->withInput();
        }

        if ($this->currencies->isEnabled($code)) {
            return back()
                ->withErrors(['currency_code' => __('currencies.validation.already_enabled')])
                ->withInput();
        }

        BusinessCurrency::create([
            'business_id' => $this->context->currentId(),
            'currency_code' => $code,
        ]);

        return back()->with('status', __('currencies.status_enabled', ['currency' => $code]));
    }

    /**
     * Disable a currency for the current business (history is untouched).
     */
    public function destroyCurrency(Currency $currency): RedirectResponse
    {
        BusinessCurrency::where('business_id', $this->context->currentId())
            ->where('currency_code', $currency->code)
            ->delete();

        return back()->with('status', __('currencies.status_disabled', ['currency' => $currency->code]));
    }

    /**
     * Add or correct the exchange-rate branch for a date.
     */
    public function storeRate(StoreExchangeRateRequest $request): RedirectResponse
    {
        $code = strtoupper($request->validated('currency_code'));

        $rate = (string) $request->validated('rate');
        $effectiveDate = $request->validated('effective_date');

        $rate = Decimal::normalize($rate, CurrencyService::RATE_SCALE);

        ExchangeRate::updateOrCreate(
            [
                'business_id' => $this->context->currentId(),
                'currency_code' => $code,
                'effective_date' => $effectiveDate,
            ],
            [
                'rate' => $rate,
                'created_by' => $this->context->user()?->id,
            ],
        );

        return back()->with('status', __('currencies.status_rate_saved', ['currency' => $code]));
    }

    /**
     * Delete an exchange-rate branch (global business scope → 404 across
     * tenants).
     */
    public function destroyRate(ExchangeRate $exchangeRate): RedirectResponse
    {
        $code = $exchangeRate->currency_code;
        $exchangeRate->delete();

        return back()->with('status', __('currencies.status_rate_deleted', ['currency' => $code]));
    }
}
