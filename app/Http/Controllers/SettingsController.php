<?php

namespace App\Http\Controllers;

use App\Http\Requests\Settings\UpdateSettingsRequest;
use App\Models\ExchangeRate;
use App\Services\BusinessContext;
use App\Services\BusinessSettings;
use App\Services\CurrencyService;
use App\Services\DocumentThemeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Business settings (Batch 9).
 *
 * Settings are scoped to the current business resolved from BusinessContext;
 * this controller never reads a business_id (or any other tenant identifier)
 * from the request. Every key was already whitelisted by the form request and
 * is re-filtered by BusinessSettings before it can reach the database.
 *
 * Batch 19 adds the base-currency (regional.currency) management: the index
 * renders the currency catalogue, enabled currencies and exchange rates, and
 * update() acts as the base-currency change gate — an immutable base once any
 * financial history exists (drafts alone never pin the base).
 */
class SettingsController extends Controller
{
    public function index(BusinessSettings $settings, BusinessContext $context, CurrencyService $currencies, DocumentThemeService $themes): View
    {
        $base = $currencies->baseCurrency();

        return view('settings.index', [
            'business' => $context->current(),
            'values' => $settings->all(),
            'editable' => auth()->user()?->can('settings.manage') ?? false,
            'timezones' => \DateTimeZone::listIdentifiers(),
            'dateFormatLabels' => config('settings.options.date_formats', []),
            'timeFormatLabels' => config('settings.options.time_formats', []),
            'locales' => config('localization.supported', []),
            'currency_base' => $base,
            'currency_active' => $currencies->allActive(),
            'currency_enabled' => $currencies->enabledCurrencies(),
            'currency_rates' => ExchangeRate::query()
                ->with('currency')
                ->orderBy('currency_code')
                ->orderByDesc('effective_date')
                ->get(),
            'currency_history_locked' => $currencies->hasFinancialHistory(),
            'document_themes' => $themes->themes('invoice'),
            'document_logo_url' => $themes->logoUrl(),
        ]);
    }

    public function update(UpdateSettingsRequest $request, BusinessContext $context, BusinessSettings $settings, CurrencyService $currencies): RedirectResponse
    {
        $validated = $request->validated();

        // The business name lives on the businesses table (the shell and the
        // business switcher read Business::name at render time), so it is
        // updated in place instead of being duplicated into settings rows.
        $business = $context->current();

        if (isset($validated['name'])) {
            $business->update(['name' => $validated['name']]);
            unset($validated['name']);
        }

        // Batch 19 base-currency gate: once any finalized financial history
        // exists (non-draft invoices, payments, expenses, non-draft
        // quotations), the base is pinned — changing it would silently rewrite
        // the meaning of every recorded base_amount. Drafts alone are safe:
        // they can still be repriced before anything is final.
        if (isset($validated['regional']['currency'])) {
            $newBase = strtoupper($validated['regional']['currency']);

            if ($newBase !== $currencies->baseCurrency() && $currencies->hasFinancialHistory($business)) {
                throw ValidationException::withMessages([
                    'regional.currency' => __('settings.validation.currency_locked'),
                ]);
            }

            $validated['regional']['currency'] = $newBase;
        }

        // Batch 24 document logo lifecycle. The upload itself is never stored
        // under a client filename: Laravel generates the stored filename under
        // a business-owned directory. SVG is deliberately not accepted by the
        // form request because browser-rendered SVG can contain executable
        // content. Replacing/removing a logo deletes only the current
        // business's previously configured public-disk object.
        $document = $validated['document'] ?? [];
        $removeLogo = (bool) ($document['remove_logo'] ?? false);
        unset($validated['document']['logo'], $validated['document']['remove_logo']);

        $oldLogoPath = $settings->get('document.logo_path');

        if ($request->hasFile('document.logo')) {
            $path = $request->file('document.logo')->store('business-documents/'.$business->id, 'public');

            if (is_string($oldLogoPath) && $oldLogoPath !== '' && $oldLogoPath !== $path) {
                Storage::disk('public')->delete($oldLogoPath);
            }

            $validated['document']['logo_path'] = $path;
        } elseif ($removeLogo) {
            if (is_string($oldLogoPath) && $oldLogoPath !== '') {
                Storage::disk('public')->delete($oldLogoPath);
            }

            $validated['document']['logo_path'] = null;
        }

        // Only whitelisted group.key definitions are persisted. The form
        // request returns nested keys (group/key) because validation treats
        // dots as paths; flatten them back into group.key pairs for the
        // whitelist-filtered service.
        $settings->updateMany(Arr::dot($validated));

        return redirect()->route('settings.index')
            ->with('status', __('settings.saved'));
    }
}
