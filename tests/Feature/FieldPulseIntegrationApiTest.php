<?php

namespace Tests\Feature;

use App\Enums\ProductType;
use App\Models\Business;
use App\Models\Customer;
use App\Models\FieldPulseCollection;
use App\Models\FieldPulseIntegration;
use App\Models\FieldPulseOrder;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FieldPulseIntegrationApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'fieldpulse.enabled' => true,
            'fieldpulse.token' => 'fieldpulse-test-token',
            'fieldpulse.page_size' => 1,
            'fieldpulse.max_events_per_request' => 20,
        ]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])
            ->assertExitCode(0);
    }

    public function test_integration_authentication_and_business_scope_are_enforced(): void
    {
        $businessA = Business::create(['name' => 'Alpha']);
        $businessB = Business::create(['name' => 'Beta']);
        FieldPulseIntegration::create([
            'business_id' => $businessA->id,
            'organization_key' => 'alpha-fieldpulse',
            'enabled' => true,
        ]);
        FieldPulseIntegration::create([
            'business_id' => $businessB->id,
            'organization_key' => 'beta-fieldpulse',
            'enabled' => true,
        ]);

        $this->getJson('/api/fieldpulse/v1/health')
            ->assertStatus(401);

        $this->withHeaders([
            'Authorization' => 'Bearer wrong-token',
            'X-BusinessOS-Organization' => 'alpha-fieldpulse',
            'X-FieldPulse-Tenant' => (string) Str::uuid(),
        ])->getJson('/api/fieldpulse/v1/health')
            ->assertStatus(401);

        $this->withHeaders($this->headers('alpha-fieldpulse'))
            ->getJson('/api/fieldpulse/v1/health')
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('business.id', $businessA->id);

        FieldPulseIntegration::query()
            ->where('business_id', $businessA->id)
            ->update(['enabled' => false]);

        $this->withHeaders($this->headers('alpha-fieldpulse'))
            ->getJson('/api/fieldpulse/v1/health')
            ->assertStatus(403);
    }

    public function test_master_data_is_business_scoped_cursor_paged_and_exposes_default_prices(): void
    {
        $businessA = Business::create(['name' => 'Alpha']);
        $businessB = Business::create(['name' => 'Beta']);
        FieldPulseIntegration::create([
            'business_id' => $businessA->id,
            'organization_key' => 'alpha-fieldpulse',
            'enabled' => true,
        ]);

        $productA1 = $this->product(
            $businessA,
            'A-001',
            'Alpha One',
            '120.5000',
        );
        $productA2 = $this->product(
            $businessA,
            'A-002',
            'Alpha Two',
            '80.0000',
        );
        $this->product(
            $businessB,
            'B-001',
            'Beta Product',
            '999.0000',
        );
        $customerA = $this->customer(
            $businessA,
            'Alpha Shop',
            'alpha@example.test',
        );
        $this->customer(
            $businessB,
            'Beta Shop',
            'beta@example.test',
        );

        $first = $this->withHeaders($this->headers('alpha-fieldpulse'))
            ->getJson('/api/fieldpulse/v1/master-data?types=products')
            ->assertOk()
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.sku', $productA1->sku)
            ->assertJsonMissing(['sku' => 'B-001']);

        $cursor = $first->json('next_cursor');
        $this->assertNotEmpty($cursor);
        $this->assertTrue($first->json('has_more'));

        $this->withHeaders($this->headers('alpha-fieldpulse'))
            ->getJson(
                '/api/fieldpulse/v1/master-data?types=products&cursor='
                .urlencode($cursor),
            )
            ->assertOk()
            ->assertJsonPath('data.products.0.sku', $productA2->sku)
            ->assertJsonPath('has_more', false);

        $this->withHeaders($this->headers('alpha-fieldpulse'))
            ->getJson('/api/fieldpulse/v1/master-data?types=customers')
            ->assertOk()
            ->assertJsonPath(
                'data.customers.0.external_id',
                'customer:'.$customerA->id,
            )
            ->assertJsonPath(
                'data.customers.0.price_list_external_id',
                'price-list:default',
            );

        $this->withHeaders($this->headers('alpha-fieldpulse'))
            ->getJson('/api/fieldpulse/v1/master-data?types=prices')
            ->assertOk()
            ->assertJsonPath(
                'data.prices.0.external_id',
                'price-list:default',
            )
            ->assertJsonPath('data.prices.0.currency', 'AFN')
            ->assertJsonPath(
                'data.prices.0.items.0.product_external_id',
                'product:'.$productA1->id,
            )
            ->assertJsonPath(
                'data.prices.0.items.0.price',
                120.5,
            );
    }

    public function test_customer_order_and_collection_events_are_idempotent(): void
    {
        $business = Business::create(['name' => 'Integrated Co']);
        FieldPulseIntegration::create([
            'business_id' => $business->id,
            'organization_key' => 'integrated-fieldpulse',
            'enabled' => true,
        ]);

        $customerUuid = (string) Str::uuid();
        $orderUuid = (string) Str::uuid();
        $collectionUuid = (string) Str::uuid();

        $events = [
            [
                'idempotency_key' => 'customer-'.$customerUuid.'-1',
                'type' => 'customer.upserted',
                'data' => [
                    'fieldpulse_uuid' => $customerUuid,
                    'name' => 'Kabul Market',
                    'contact_person' => 'Ahmad',
                    'phone' => '0700000000',
                    'email' => 'kabul-market@example.test',
                    'address' => 'Kabul',
                ],
            ],
            [
                'idempotency_key' => 'order-'.$orderUuid,
                'type' => 'order.approved',
                'data' => [
                    'fieldpulse_uuid' => $orderUuid,
                    'order_number' => 'FP-ORD-001',
                    'ordered_at' => '2026-09-26T10:00:00+04:30',
                    'customer' => [
                        'fieldpulse_uuid' => $customerUuid,
                    ],
                    'salesman' => [
                        'employee_code' => 'S-001',
                        'name' => 'Salesman One',
                    ],
                    'payment_type' => 'cash',
                    'currency' => 'AFN',
                    'subtotal' => 1000,
                    'discount_total' => 50,
                    'grand_total' => 950,
                    'items' => [],
                ],
            ],
            [
                'idempotency_key' => 'collection-'.$collectionUuid,
                'type' => 'collection.verified',
                'data' => [
                    'fieldpulse_uuid' => $collectionUuid,
                    'receipt_number' => 'FP-RC-001',
                    'collected_at' => '2026-09-26T11:00:00+04:30',
                    'customer' => [
                        'fieldpulse_uuid' => $customerUuid,
                    ],
                    'salesman' => [
                        'employee_code' => 'S-001',
                        'name' => 'Salesman One',
                    ],
                    'currency' => 'AFN',
                    'amount' => 500,
                    'payment_method' => 'cash',
                    'reference_number' => 'REF-1',
                    'within_geofence' => true,
                ],
            ],
        ];

        $first = $this->withHeaders(
            $this->headers('integrated-fieldpulse')
        )->postJson('/api/fieldpulse/v1/events', [
            'events' => $events,
        ])->assertOk()
            ->assertJsonPath('accepted', 3)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('results.0.accepted', true)
            ->assertJsonPath('results.1.accepted', true)
            ->assertJsonPath('results.2.accepted', true);

        $customerExternalId = $first->json(
            'results.0.external_id',
        );
        $this->assertStringStartsWith(
            'customer:',
            $customerExternalId,
        );

        $this->assertDatabaseCount('field_pulse_events', 3);
        $this->assertDatabaseCount('field_pulse_entity_links', 1);
        $this->assertDatabaseCount('field_pulse_orders', 1);
        $this->assertDatabaseCount('field_pulse_collections', 1);
        $this->assertSame(
            1,
            Customer::query()
                ->withoutGlobalScope('business')
                ->where('business_id', $business->id)
                ->count(),
        );

        $customer = Customer::query()
            ->withoutGlobalScope('business')
            ->where('business_id', $business->id)
            ->firstOrFail();
        $this->assertSame('Ahmad', $customer->name);
        $this->assertSame('Kabul Market', $customer->company_name);

        $order = FieldPulseOrder::query()
            ->where('business_id', $business->id)
            ->firstOrFail();
        $collection = FieldPulseCollection::query()
            ->where('business_id', $business->id)
            ->firstOrFail();
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame($customer->id, $collection->customer_id);
        $this->assertSame('950.0000', $order->total);
        $this->assertSame('500.0000', $collection->amount);

        $this->withHeaders(
            $this->headers('integrated-fieldpulse')
        )->postJson('/api/fieldpulse/v1/events', [
            'events' => $events,
        ])->assertOk()
            ->assertJsonPath('accepted', 3)
            ->assertJsonPath(
                'results.0.external_id',
                $customerExternalId,
            );

        $this->assertDatabaseCount('field_pulse_events', 3);
        $this->assertDatabaseCount('field_pulse_entity_links', 1);
        $this->assertDatabaseCount('field_pulse_orders', 1);
        $this->assertDatabaseCount('field_pulse_collections', 1);
    }

    private function headers(string $organizationKey): array
    {
        return [
            'Authorization' => 'Bearer fieldpulse-test-token',
            'X-BusinessOS-Organization' => $organizationKey,
            'X-FieldPulse-Tenant' => (string) Str::uuid(),
        ];
    }

    private function product(
        Business $business,
        string $sku,
        string $name,
        string $price,
    ): Product {
        $product = new Product;
        $product->forceFill([
            'business_id' => $business->id,
            'type' => ProductType::Product,
            'name' => $name,
            'sku' => $sku,
            'description' => null,
            'sale_price' => $price,
        ]);
        $product->save();

        return $product;
    }

    private function customer(
        Business $business,
        string $name,
        string $email,
    ): Customer {
        $customer = new Customer;
        $customer->forceFill([
            'business_id' => $business->id,
            'name' => $name,
            'company_name' => $name,
            'email' => $email,
            'phone' => null,
            'address' => null,
            'opening_balance' => '0.0000',
        ]);
        $customer->save();

        return $customer;
    }
}
