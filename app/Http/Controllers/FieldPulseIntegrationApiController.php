<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\FieldPulseIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FieldPulseIntegrationApiController extends Controller
{
    public function health(Request $request): JsonResponse
    {
        $business = $this->business($request);

        return response()->json([
            'connected' => true,
            'status' => 'healthy',
            'business' => [
                'id' => $business->id,
                'name' => $business->name,
            ],
            'organization_key' => $request->header(
                'X-BusinessOS-Organization',
            ),
        ]);
    }

    public function masterData(
        Request $request,
        FieldPulseIntegrationService $service,
    ): JsonResponse {
        $validated = $request->validate([
            'types' => ['required', 'string'],
            'cursor' => ['nullable', 'string', 'max:1000'],
        ]);
        $types = collect(explode(',', $validated['types']))
            ->map(fn (string $type) => trim($type))
            ->filter()
            ->unique()
            ->values();

        abort_if(
            $types->count() !== 1,
            422,
            'Exactly one master-data type is required per request.',
        );

        $type = $types->first();
        abort_unless(
            in_array($type, ['products', 'customers', 'prices'], true),
            422,
            'Unsupported master-data type.',
        );

        return response()->json(
            $service->masterData(
                $this->business($request),
                $type,
                $validated['cursor'] ?? null,
            )
        );
    }

    public function events(
        Request $request,
        FieldPulseIntegrationService $service,
    ): JsonResponse {
        $limit = max(
            1,
            min(
                500,
                (int) config(
                    'fieldpulse.max_events_per_request',
                    200,
                ),
            ),
        );
        $validated = $request->validate([
            'events' => [
                'required',
                'array',
                'min:1',
                'max:'.$limit,
            ],
            'events.*.idempotency_key' => [
                'required',
                'string',
                'max:191',
            ],
            'events.*.type' => [
                'required',
                'string',
                Rule::in([
                    'customer.upserted',
                    'order.approved',
                    'collection.verified',
                ]),
            ],
            'events.*.data' => ['required', 'array'],
        ]);

        return response()->json(
            $service->receiveEvents(
                $this->business($request),
                (string) $request->attributes->get(
                    'fieldpulse_tenant_uuid',
                ),
                $validated['events'],
            )
        );
    }

    private function business(Request $request): Business
    {
        $business = $request->attributes->get(
            'fieldpulse_business',
        );

        abort_unless($business instanceof Business, 403);

        return $business;
    }
}
