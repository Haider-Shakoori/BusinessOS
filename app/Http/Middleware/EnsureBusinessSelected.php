<?php

namespace App\Http\Middleware;

use App\Services\BusinessContext;
use App\Services\SaasUsageService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures authenticated application routes have a valid current business.
 *
 * Behavior:
 * - Guests are handled by the `auth` middleware (route group acting first).
 * - Authenticated users with no businesses are sent to onboarding.
 * - Authenticated users with a stale or unauthorized stored selection have it
 *   discarded and re-resolved safely by BusinessContext (never trusted raw).
 * - The onboarding page itself always renders so no redirect loop can occur.
 *
 * Batch 6 deliberately performs NO permission checks — that is Batch 7.
 */
class EnsureBusinessSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(BusinessContext::class);

        if ($request->routeIs('business.create')) {
            // Onboarding must always be reachable for users without a business.
            return $next($request);
        }

        if ($context->businesses()->isEmpty()) {
            return redirect()->route('business.create');
        }

        // Resolve (and persist) the authoritative current business.
        $business = $context->current();

        if (
            $business
            && ! $request->user()?->is_super_admin
            && ! app(SaasUsageService::class)->isOperational($business)
        ) {
            abort(403, __('saas.subscription_inactive'));
        }

        return $next($request);
    }
}
