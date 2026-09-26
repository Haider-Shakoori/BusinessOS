<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Customer;
use App\Models\FieldPulseCollection;
use App\Models\FieldPulseEntityLink;
use App\Models\FieldPulseEvent;
use App\Models\FieldPulseOrder;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class FieldPulseIntegrationService
{
    public function masterData(
        Business $business,
        string $type,
        ?string $cursor,
    ): array {
        return match ($type) {
            'products' => $this->products($business, $cursor),
            'customers' => $this->customers($business, $cursor),
            'prices' => $this->prices($business, $cursor),
            default => throw new InvalidArgumentException(
                'Unsupported FieldPulse master-data type.',
            ),
        };
    }

    public function receiveEvents(
        Business $business,
        string $tenantUuid,
        array $events,
    ): array {
        $results = [];
        $accepted = 0;

        foreach ($events as $event) {
            if (! is_array($event)) {
                $results[] = [
                    'accepted' => false,
                    'status' => 'rejected',
                    'message' => 'Event must be an object.',
                ];

                continue;
            }

            $result = $this->receiveEvent(
                $business,
                $tenantUuid,
                $event,
            );
            $results[] = $result;

            if ($result['accepted']) {
                $accepted++;
            }
        }

        return [
            'status' => $accepted === count($events)
                ? 'success'
                : ($accepted > 0 ? 'partial' : 'failed'),
            'accepted' => $accepted,
            'results' => $results,
        ];
    }

    private function products(Business $business, ?string $cursor): array
    {
        [$query, $pageSize] = $this->pagedQuery(
            Product::withTrashed()
                ->withoutGlobalScope('business')
                ->where('business_id', $business->id)
                ->with('unit'),
            $cursor,
        );
        $rows = $query->limit($pageSize + 1)->get();
        [$page, $hasMore, $nextCursor] = $this->page($rows, $pageSize);
        $currency = $this->baseCurrency($business);

        return [
            'data' => [
                'products' => $page->map(
                    fn (Product $product) => [
                        'external_id' => 'product:'.$product->id,
                        'version' => $product->updated_at?->toIso8601String(),
                        'sku' => $product->sku,
                        'barcode' => null,
                        'name' => $product->name,
                        'description' => $product->description,
                        'unit' => $product->unit?->short_name
                            ?: $product->unit?->name
                            ?: 'pcs',
                        'base_price' => (float) $product->sale_price,
                        'currency' => $currency,
                        'is_active' => $product->deleted_at === null,
                    ]
                )->values()->all(),
            ],
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    private function customers(
        Business $business,
        ?string $cursor,
    ): array {
        [$query, $pageSize] = $this->pagedQuery(
            Customer::withTrashed()
                ->withoutGlobalScope('business')
                ->where('business_id', $business->id),
            $cursor,
        );
        $rows = $query->limit($pageSize + 1)->get();
        [$page, $hasMore, $nextCursor] = $this->page($rows, $pageSize);

        return [
            'data' => [
                'customers' => $page->map(
                    function (Customer $customer): array {
                        $displayName = trim(
                            (string) (
                                $customer->company_name
                                ?: $customer->name
                            )
                        );

                        return [
                            'external_id' => 'customer:'.$customer->id,
                            'version' => $customer->updated_at?->toIso8601String(),
                            'code' => 'BOS-CUST-'.$customer->id,
                            'name' => $displayName !== ''
                                ? $displayName
                                : $customer->name,
                            'contact_person' => $customer->company_name
                                ? $customer->name
                                : null,
                            'phone' => $customer->phone,
                            'alternate_phone' => null,
                            'email' => $customer->email,
                            'address' => $customer->address,
                            'latitude' => null,
                            'longitude' => null,
                            'geofence_radius_meters' => 100,
                            'price_list_external_id' => 'price-list:default',
                            'is_active' => $customer->deleted_at === null,
                        ];
                    }
                )->values()->all(),
            ],
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    private function prices(Business $business, ?string $cursor): array
    {
        [$query, $pageSize] = $this->pagedQuery(
            Product::query()
                ->withoutGlobalScope('business')
                ->where('business_id', $business->id),
            $cursor,
        );
        $rows = $query->limit($pageSize + 1)->get();
        [$page, $hasMore, $nextCursor] = $this->page($rows, $pageSize);

        if ($page->isEmpty()) {
            return [
                'data' => ['prices' => []],
                'next_cursor' => $nextCursor,
                'has_more' => false,
            ];
        }

        return [
            'data' => [
                'prices' => [[
                    'external_id' => 'price-list:default',
                    'version' => optional($page->last()->updated_at)
                        ?->toIso8601String(),
                    'code' => 'BUSINESSOS',
                    'name' => 'BusinessOS Default Price List',
                    'currency' => $this->baseCurrency($business),
                    'effective_from' => null,
                    'effective_to' => null,
                    'is_active' => true,
                    'items' => $page->map(
                        fn (Product $product) => [
                            'product_external_id' => 'product:'.$product->id,
                            'sku' => $product->sku,
                            'min_quantity' => 1,
                            'price' => (float) $product->sale_price,
                        ]
                    )->values()->all(),
                ]],
            ],
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];
    }

    private function receiveEvent(
        Business $business,
        string $tenantUuid,
        array $payload,
    ): array {
        $idempotencyKey = trim(
            (string) ($payload['idempotency_key'] ?? '')
        );
        $eventType = trim((string) ($payload['type'] ?? ''));
        $data = is_array($payload['data'] ?? null)
            ? $payload['data']
            : [];

        if ($idempotencyKey === '' || $eventType === '') {
            return [
                'idempotency_key' => $idempotencyKey,
                'accepted' => false,
                'status' => 'rejected',
                'message' => 'idempotency_key and type are required.',
            ];
        }

        $event = FieldPulseEvent::query()->firstOrCreate(
            [
                'business_id' => $business->id,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'fieldpulse_tenant_uuid' => $tenantUuid,
                'event_type' => $eventType,
                'fieldpulse_uuid' => $this->nullableString(
                    $data['fieldpulse_uuid'] ?? null,
                ),
                'status' => 'processing',
                'payload' => $payload,
                'received_at' => now(),
            ],
        );

        if ($event->status === 'accepted') {
            return [
                'idempotency_key' => $idempotencyKey,
                'accepted' => true,
                'status' => 'accepted',
                'external_id' => $event->external_id,
            ];
        }

        try {
            $externalId = DB::transaction(
                fn (): string => match ($eventType) {
                    'customer.upserted' => $this->upsertCustomer(
                        $business,
                        $data,
                    ),
                    'order.approved' => $this->receiveOrder(
                        $business,
                        $data,
                    ),
                    'collection.verified' => $this->receiveCollection(
                        $business,
                        $data,
                    ),
                    default => throw new InvalidArgumentException(
                        'Unsupported FieldPulse event type.',
                    ),
                }
            );

            $event->update([
                'fieldpulse_tenant_uuid' => $tenantUuid,
                'event_type' => $eventType,
                'fieldpulse_uuid' => $this->nullableString(
                    $data['fieldpulse_uuid'] ?? null,
                ),
                'status' => 'accepted',
                'external_id' => $externalId,
                'payload' => $payload,
                'error_message' => null,
                'received_at' => now(),
            ]);

            return [
                'idempotency_key' => $idempotencyKey,
                'accepted' => true,
                'status' => 'accepted',
                'external_id' => $externalId,
            ];
        } catch (Throwable $exception) {
            $event->update([
                'status' => 'failed',
                'error_message' => mb_substr(
                    $exception->getMessage(),
                    0,
                    4000,
                ),
                'payload' => $payload,
                'received_at' => now(),
            ]);

            return [
                'idempotency_key' => $idempotencyKey,
                'accepted' => false,
                'status' => 'rejected',
                'message' => $exception->getMessage(),
            ];
        }
    }

    private function upsertCustomer(
        Business $business,
        array $data,
    ): string {
        $fieldpulseUuid = $this->requiredUuid($data);
        $customer = $this->customerFromExternalId(
            $business,
            $data['businessos_id'] ?? null,
        );

        if (! $customer) {
            $link = FieldPulseEntityLink::query()
                ->where('business_id', $business->id)
                ->where('entity_type', 'customer')
                ->where('fieldpulse_uuid', $fieldpulseUuid)
                ->first();

            if ($link) {
                $customer = Customer::withTrashed()
                    ->withoutGlobalScope('business')
                    ->where('business_id', $business->id)
                    ->find($link->local_id);
            }
        }

        $sourceName = trim((string) ($data['name'] ?? ''));
        if ($sourceName === '') {
            throw new InvalidArgumentException(
                'FieldPulse customer name is required.',
            );
        }

        $contact = $this->nullableString(
            $data['contact_person'] ?? null,
        );
        $customer ??= new Customer;
        $customer->forceFill([
            'business_id' => $business->id,
            'name' => $contact ?: $sourceName,
            'company_name' => $sourceName,
            'email' => $this->nullableString($data['email'] ?? null),
            'phone' => $this->nullableString($data['phone'] ?? null),
            'address' => $this->nullableString($data['address'] ?? null),
        ]);
        $customer->save();

        if (method_exists($customer, 'trashed') && $customer->trashed()) {
            $customer->restore();
        }

        FieldPulseEntityLink::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'entity_type' => 'customer',
                'fieldpulse_uuid' => $fieldpulseUuid,
            ],
            ['local_id' => $customer->id],
        );

        return 'customer:'.$customer->id;
    }

    private function receiveOrder(
        Business $business,
        array $data,
    ): string {
        $fieldpulseUuid = $this->requiredUuid($data);
        $orderNumber = trim((string) ($data['order_number'] ?? ''));

        if ($orderNumber === '') {
            throw new InvalidArgumentException(
                'FieldPulse order_number is required.',
            );
        }

        $customer = $this->resolveCustomerReference(
            $business,
            is_array($data['customer'] ?? null)
                ? $data['customer']
                : [],
        );

        $order = FieldPulseOrder::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'fieldpulse_uuid' => $fieldpulseUuid,
            ],
            [
                'customer_id' => $customer?->id,
                'order_number' => $orderNumber,
                'ordered_at' => $data['ordered_at'] ?? null,
                'payment_type' => $this->nullableString(
                    $data['payment_type'] ?? null,
                ),
                'currency_code' => strtoupper(
                    trim((string) ($data['currency'] ?? 'AFN'))
                ),
                'subtotal' => (float) ($data['subtotal'] ?? 0),
                'discount_total' => (float) (
                    $data['discount_total'] ?? 0
                ),
                'total' => (float) ($data['grand_total'] ?? 0),
                'salesman_code' => $this->nullableString(
                    data_get($data, 'salesman.employee_code'),
                ),
                'salesman_name' => $this->nullableString(
                    data_get($data, 'salesman.name'),
                ),
                'status' => 'received',
                'payload' => $data,
            ],
        );

        return 'fieldpulse-order:'.$order->id;
    }

    private function receiveCollection(
        Business $business,
        array $data,
    ): string {
        $fieldpulseUuid = $this->requiredUuid($data);
        $receipt = trim(
            (string) ($data['receipt_number'] ?? '')
        );

        if ($receipt === '') {
            throw new InvalidArgumentException(
                'FieldPulse receipt_number is required.',
            );
        }

        $customer = $this->resolveCustomerReference(
            $business,
            is_array($data['customer'] ?? null)
                ? $data['customer']
                : [],
        );

        $collection = FieldPulseCollection::query()->updateOrCreate(
            [
                'business_id' => $business->id,
                'fieldpulse_uuid' => $fieldpulseUuid,
            ],
            [
                'customer_id' => $customer?->id,
                'receipt_number' => $receipt,
                'collected_at' => $data['collected_at'] ?? null,
                'currency_code' => strtoupper(
                    trim((string) ($data['currency'] ?? 'AFN'))
                ),
                'amount' => (float) ($data['amount'] ?? 0),
                'payment_method' => $this->nullableString(
                    $data['payment_method'] ?? null,
                ),
                'reference_number' => $this->nullableString(
                    $data['reference_number'] ?? null,
                ),
                'salesman_code' => $this->nullableString(
                    data_get($data, 'salesman.employee_code'),
                ),
                'salesman_name' => $this->nullableString(
                    data_get($data, 'salesman.name'),
                ),
                'within_geofence' => array_key_exists(
                    'within_geofence',
                    $data,
                )
                    ? (bool) $data['within_geofence']
                    : null,
                'status' => 'received',
                'payload' => $data,
            ],
        );

        return 'fieldpulse-collection:'.$collection->id;
    }

    private function resolveCustomerReference(
        Business $business,
        array $reference,
    ): ?Customer {
        $customer = $this->customerFromExternalId(
            $business,
            $reference['businessos_id'] ?? null,
        );

        if ($customer) {
            return $customer;
        }

        $fieldpulseUuid = $this->nullableString(
            $reference['fieldpulse_uuid'] ?? null,
        );

        if ($fieldpulseUuid === null) {
            return null;
        }

        $link = FieldPulseEntityLink::query()
            ->where('business_id', $business->id)
            ->where('entity_type', 'customer')
            ->where('fieldpulse_uuid', $fieldpulseUuid)
            ->first();

        return $link
            ? Customer::withTrashed()
                ->withoutGlobalScope('business')
                ->where('business_id', $business->id)
                ->find($link->local_id)
            : null;
    }

    private function customerFromExternalId(
        Business $business,
        mixed $externalId,
    ): ?Customer {
        $externalId = $this->nullableString($externalId);

        if (
            $externalId === null
            || ! str_starts_with($externalId, 'customer:')
        ) {
            return null;
        }

        $id = (int) str($externalId)->after('customer:')->toString();

        if ($id <= 0) {
            return null;
        }

        return Customer::withTrashed()
            ->withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->find($id);
    }

    private function pagedQuery(
        Builder $query,
        ?string $cursor,
    ): array {
        $pageSize = max(
            1,
            min(500, (int) config('fieldpulse.page_size', 200)),
        );
        $decoded = $this->decodeCursor($cursor);

        if ($decoded !== null) {
            // Bind the cursor timestamp as a DateTime value so Laravel formats it
            // using the active database grammar. This keeps cursor pagination
            // consistent across MySQL and SQLite (used by CI), where raw ISO-8601
            // strings do not compare equal to database datetime strings.
            $updatedAt = \Illuminate\Support\Carbon::parse(
                $decoded['updated_at'],
            );

            $query->where(function (Builder $builder) use ($decoded, $updatedAt): void {
                $builder
                    ->where('updated_at', '>', $updatedAt)
                    ->orWhere(function (Builder $same) use ($decoded, $updatedAt): void {
                        $same->where(
                            'updated_at',
                            '=',
                            $updatedAt,
                        )->where('id', '>', $decoded['id']);
                    });
            });
        }

        return [
            $query->orderBy('updated_at')->orderBy('id'),
            $pageSize,
        ];
    }

    private function page($rows, int $pageSize): array
    {
        $hasMore = $rows->count() > $pageSize;
        $page = $rows->take($pageSize)->values();
        $last = $page->last();

        return [
            $page,
            $hasMore,
            $last
                ? $this->encodeCursor(
                    $last->updated_at?->toIso8601String()
                        ?? '1970-01-01T00:00:00+00:00',
                    (int) $last->id,
                )
                : null,
        ];
    }

    private function encodeCursor(string $updatedAt, int $id): string
    {
        return rtrim(
            strtr(
                base64_encode(json_encode([
                    'updated_at' => $updatedAt,
                    'id' => $id,
                ], JSON_THROW_ON_ERROR)),
                '+/',
                '-_',
            ),
            '=',
        );
    }

    private function decodeCursor(?string $cursor): ?array
    {
        $cursor = trim((string) ($cursor ?? ''));

        if ($cursor === '') {
            return null;
        }

        $encoded = strtr($cursor, '-_', '+/');
        $padding = strlen($encoded) % 4;
        if ($padding > 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }

        $decoded = json_decode(
            (string) base64_decode($encoded, true),
            true,
        );

        if (
            ! is_array($decoded)
            || ! is_string($decoded['updated_at'] ?? null)
            || ! is_numeric($decoded['id'] ?? null)
        ) {
            throw new InvalidArgumentException(
                'FieldPulse cursor is invalid.',
            );
        }

        return [
            'updated_at' => $decoded['updated_at'],
            'id' => (int) $decoded['id'],
        ];
    }

    private function baseCurrency(Business $business): string
    {
        return strtoupper(
            (string) (
                Setting::query()
                    ->where('business_id', $business->id)
                    ->where('group', 'regional')
                    ->where('key', 'currency')
                    ->value('value')
                ?? config(
                    'settings.definitions.regional.currency.default',
                    'AFN',
                )
            )
        );
    }

    private function requiredUuid(array $data): string
    {
        $uuid = trim((string) ($data['fieldpulse_uuid'] ?? ''));

        if (! \Illuminate\Support\Str::isUuid($uuid)) {
            throw new InvalidArgumentException(
                'FieldPulse entity UUID is required.',
            );
        }

        return $uuid;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
