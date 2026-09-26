<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityRecorder
{
    public function __construct(private readonly BusinessContext $context)
    {
        //
    }

    public function record(Request $request): void
    {
        if ($this->context->currentId() === null || ! $request->user()) {
            return;
        }

        if (in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        $route = $request->route();
        $routeName = $route?->getName();

        if ($routeName === null || str_starts_with($routeName, 'notifications.')) {
            return;
        }

        $subjectType = null;
        $subjectId = null;

        foreach ($route?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof \Illuminate\Database\Eloquent\Model) {
                $subjectType = $parameter::class;
                $subjectId = (string) $parameter->getKey();
                break;
            }
        }

        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => $routeName,
            'route_name' => $routeName,
            'method' => strtoupper($request->method()),
            'path' => '/'.ltrim($request->path(), '/'),
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $this->describe($routeName),
            'properties' => [
                'route_parameters' => $this->safeRouteParameters($route?->parameters() ?? []),
            ],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);
    }

    private function describe(string $routeName): string
    {
        return str($routeName)
            ->replace(['.', '-', '_'], ' ')
            ->headline()
            ->toString();
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, scalar|null>
     */
    private function safeRouteParameters(array $parameters): array
    {
        $safe = [];

        foreach ($parameters as $key => $value) {
            if ($value instanceof \Illuminate\Database\Eloquent\Model) {
                $safe[$key] = $value->getKey();
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
