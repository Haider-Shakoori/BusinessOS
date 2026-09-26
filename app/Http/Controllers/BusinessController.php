<?php

namespace App\Http\Controllers;

use App\Http\Requests\Business\StoreBusinessRequest;
use App\Models\Currency;
use App\Services\BusinessContext;
use App\Services\BusinessOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;

class BusinessController extends Controller
{
    /**
     * Screenshot-aligned first-business onboarding.
     */
    public function create(): View
    {
        return view('business.create', [
            'countries' => config('onboarding.countries', []),
            'industries' => config('onboarding.industries', []),
            'timezones' => config('onboarding.timezones', []),
            'onboardingModules' => config('onboarding.modules', []),
            'supportedLocales' => config('localization.supported', []),
            'currencies' => Currency::query()->where('is_active', true)->orderBy('code')->get(),
            'defaults' => config('onboarding.defaults', []),
        ]);
    }

    /**
     * Create the first business and initialize its workspace preferences.
     */
    public function store(StoreBusinessRequest $request, BusinessOnboardingService $onboarding): RedirectResponse
    {
        $business = $onboarding->create($request, $request->user());

        $locale = $request->validated('locale');

        if (is_string($locale) && $locale !== '') {
            Session::put(config('localization.session_key', 'locale'), $locale);
        }

        return redirect()->route('app.home')->with('status', __('business.created', [
            'business' => $business->name,
        ]));
    }

    /**
     * Switch the current business (POST + CSRF only).
     */
    public function switch(Request $request): RedirectResponse
    {
        $business = app(BusinessContext::class)->switchTo((int) $request->input('business_id'));

        if ($business === null) {
            abort(403);
        }

        return redirect()->route('app.home')
            ->with('status', __('business.switch_success', ['business' => $business->name]));
    }
}
