<?php

namespace App\Http\Middleware;

use App\Services\BusinessSettings;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional-tax feature middleware: `tax-enabled`
 *
 * Blocks everything tax-related for the current business while the
 * general.tax_enabled setting is off — regardless of the user's taxes.*
 * permissions or how many tax rows exist. This is a FEATURE gate, composed
 * independently from the module and permission gates, mirroring the Batch 8
 * "module enablement is not authorization" convention:
 *
 *   - Guests are rejected with an authentication challenge (redirect to login).
 *   - The setting is resolved only through BusinessSettings (current business
 *     from BusinessContext), never from a request-supplied business_id.
 *   - Disabled ⇒ plain 403; the page is never reached and rows are never
 *     touched. Disabling preserves tax definitions; re-enabling restores
 *     access to the same rows without recreation.
 *
 * `Tax::exists()` never implies the feature is enabled — only this setting
 * does, so tax routes must carry this middleware even when tax rows exist.
 */
class EnsureTaxEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if (! (bool) app(BusinessSettings::class)->get('general.tax_enabled')) {
            abort(403);
        }

        return $next($request);
    }
}
