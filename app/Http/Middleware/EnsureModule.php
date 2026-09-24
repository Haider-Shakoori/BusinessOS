<?php

namespace App\Http\Middleware;

use App\Services\ModuleManager;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module middleware: `module:customers`
 *
 * Security behavior (mirrors EnsurePermission conventions):
 * - Guests are rejected with an authentication challenge (redirect to login).
 * - Unknown (unregistered) module keys are denied (403), never silently treated
 *   as enabled.
 * - Disabled modules are denied (403).
 * - The current business is resolved from BusinessContext, so a stale or forged
 *   session is re-resolved before the check.
 * - This middleware checks MODULE AVAILABILITY ONLY. Permission enforcement
 *   is the responsibility of the separate `permission:` middleware — the two
 *   are composed independently and never collapsed.
 */
class EnsureModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if ($request->user() === null) {
            throw new AuthenticationException('Unauthenticated.');
        }

        $manager = app(ModuleManager::class);

        if (! $manager->isRegistered($module) || ! $manager->isEnabled($module)) {
            abort(403);
        }

        return $next($request);
    }
}
