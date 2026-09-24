<?php

namespace App\Http\Middleware;

use App\Services\MembershipAuthorization;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission middleware: `permission:settings.manage`
 *
 * Security behavior:
 * - Guests are rejected with an authentication challenge (redirect to login),
 *   never evaluated against a business.
 * - The check always runs inside the CURRENT business (BusinessContext), so a
 *   stale or forged current business is re-resolved before authorization.
 * - Unauthorized memberships receive a plain 403 that does not reveal whether
 *   restricted resources exist — the page itself is never reached.
 * - The current business is never switched here; users are NOT redirected to
 *   a different business when their current one lacks the permission.
 *
 * The middleware itself is the security boundary (hidden UI is not).
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if ($request->user() === null) {
            throw new AuthenticationException('Unauthenticated.');
        }

        if (! app(MembershipAuthorization::class)->can($permission, $request->user())) {
            abort(403);
        }

        return $next($request);
    }
}
