<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Services\BusinessContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ActivityLogMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array(strtoupper($request->method()), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        if (! $request->user()) {
            return $response;
        }

        try {
            $businessId = app(BusinessContext::class)->currentId();

            if ($businessId === null) {
                return $response;
            }

            ActivityLog::create([
                'business_id' => $businessId,
                'user_id' => $request->user()->id,
                'event' => $this->eventFor($request),
                'route_name' => $request->route()?->getName(),
                'method' => strtoupper($request->method()),
                'path' => '/'.ltrim($request->path(), '/'),
                'status_code' => $response->getStatusCode(),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
                'meta' => [
                    'entity_id' => $this->routeEntityId($request),
                    'successful' => $response->getStatusCode() < 400,
                ],
                'occurred_at' => now(),
            ]);
        } catch (Throwable) {
            // Auditing must never make the user's business action fail.
        }

        return $response;
    }

    private function eventFor(Request $request): string
    {
        $name = (string) ($request->route()?->getName() ?? 'request');

        return match (true) {
            str_ends_with($name, '.store') => 'created',
            str_ends_with($name, '.update') => 'updated',
            str_ends_with($name, '.destroy') => 'deleted',
            str_contains($name, '.reverse') => 'reversed',
            str_contains($name, '.void') => 'voided',
            str_contains($name, '.complete') => 'completed',
            str_contains($name, '.receive') => 'received',
            str_contains($name, '.toggle') => 'toggled',
            str_contains($name, '.mark-read') => 'notification_read',
            default => 'action',
        };
    }

    private function routeEntityId(Request $request): int|string|null
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if (is_object($parameter) && method_exists($parameter, 'getKey')) {
                return $parameter->getKey();
            }

            if (is_scalar($parameter)) {
                return $parameter;
            }
        }

        return null;
    }
}
