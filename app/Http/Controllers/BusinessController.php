<?php

namespace App\Http\Controllers;

use App\Http\Requests\Business\StoreBusinessRequest;
use App\Models\Business;
use App\Services\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BusinessController extends Controller
{
    /**
     * Show the minimal first-business onboarding page.
     */
    public function create(): View
    {
        return view('business.create');
    }

    /**
     * Create the first business and initialize the current context.
     *
     * Business creation + membership assignment + default role provisioning +
     * owner role assignment happen inside a single transaction, so a
     * partially-created tenancy/authorization state can never persist. The
     * membership and owner role always belong to the authenticated user and are
     * resolved server-side.
     */
    public function store(StoreBusinessRequest $request): RedirectResponse
    {
        $user = $request->user();

        $business = DB::transaction(function () use ($request, $user): Business {
            $business = Business::create($request->validated());
            $membership = $user->memberships()->create(['business_id' => $business->id]);

            $roles = $business->provisionDefaultRoles();
            $ownerRole = $roles[config('roles.owner_role', 'owner')] ?? null;

            if ($ownerRole !== null) {
                $membership->assignRole($ownerRole);
            }

            $business->provisionDefaultModules();

            return $business;
        });

        app(BusinessContext::class)->switchTo($business->id);

        return redirect()->route('app.home')->with('status', __('business.created'));
    }

    /**
     * Switch the current business (POST + CSRF only).
     *
     * The requested business id is validated against the authenticated user's
     * membership server-side. Unauthorized ids are rejected outright and the
     * current selection is left untouched. Redirects to a named route only.
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
