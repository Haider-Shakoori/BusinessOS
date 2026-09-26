<?php

namespace App\Http\Middleware;

use App\Models\FieldPulseIntegration;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFieldPulseIntegration
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = trim((string) config('fieldpulse.token'));
        $suppliedToken = trim((string) ($request->bearerToken() ?? ''));

        if (! config('fieldpulse.enabled') || $expectedToken === '') {
            return new JsonResponse([
                'message' => 'FieldPulse integration is not available.',
            ], 503);
        }

        if (
            $suppliedToken === ''
            || ! hash_equals($expectedToken, $suppliedToken)
        ) {
            return new JsonResponse([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $organizationKey = trim(
            (string) $request->header('X-BusinessOS-Organization', '')
        );
        $tenantUuid = trim(
            (string) $request->header('X-FieldPulse-Tenant', '')
        );

        if ($organizationKey === '' || ! Str::isUuid($tenantUuid)) {
            return new JsonResponse([
                'message' => 'FieldPulse organization and tenant headers are required.',
            ], 422);
        }

        $integration = FieldPulseIntegration::query()
            ->with('business')
            ->where('organization_key', $organizationKey)
            ->where('enabled', true)
            ->first();

        if (! $integration || ! $integration->business) {
            return new JsonResponse([
                'message' => 'FieldPulse organization mapping is not enabled.',
            ], 403);
        }

        $integration->update([
            'last_seen_tenant_uuid' => $tenantUuid,
            'last_request_at' => now(),
        ]);

        $request->attributes->set(
            'fieldpulse_integration',
            $integration,
        );
        $request->attributes->set(
            'fieldpulse_business',
            $integration->business,
        );
        $request->attributes->set(
            'fieldpulse_tenant_uuid',
            $tenantUuid,
        );

        return $next($request);
    }
}
