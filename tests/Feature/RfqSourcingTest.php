<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\Product;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRfq;
use App\Models\Supplier;
use App\Models\SupplierQuotation;
use App\Models\User;
use App\Services\RfqService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class RfqSourcingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    public function test_rfq_schema_and_workspace_exist(): void
    {
        $this->assertTrue(Schema::hasTable('purchase_rfqs'));
        $this->assertTrue(Schema::hasTable('purchase_rfq_suppliers'));
        $this->assertTrue(Schema::hasTable('supplier_quotations'));
        $this->assertTrue(Schema::hasTable('supplier_quotation_items'));

        [$user, $business] = $this->context();
        $this->actIn($user, $business);

        $this->get(route('purchasing.rfqs.index'))
            ->assertOk()
            ->assertSee('Requests for quotation');
    }

    public function test_approved_requisition_can_create_and_open_numbered_rfq(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$requisition] = $this->approvedRequisition($user);
        [$supplierA, $supplierB] = $this->suppliers();

        $this->post(route('purchasing.rfqs.store'), [
            'purchase_requisition_id' => $requisition->id,
            'issue_date' => '2026-09-27',
            'response_due_date' => '2026-10-02',
            'supplier_ids' => [$supplierA->id, $supplierB->id],
            'notes' => 'Competitive sourcing round',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $rfq = PurchaseRfq::with('supplierInvitations')->firstOrFail();

        $this->assertSame('RFQ-000001', $rfq->number);
        $this->assertSame('draft', $rfq->status);
        $this->assertCount(2, $rfq->supplierInvitations);
        $this->assertNull($rfq->supplierInvitations->first()->invited_at);

        $this->post(route('purchasing.rfqs.open', $rfq))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $rfq->refresh()->load('supplierInvitations');
        $this->assertSame('open', $rfq->status);
        $this->assertNotNull($rfq->opened_at);
        $this->assertTrue($rfq->supplierInvitations->every(fn ($invitation): bool => $invitation->invited_at !== null));
    }

    public function test_unapproved_requisition_cannot_start_sourcing(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$requisition] = $this->approvedRequisition($user);
        $requisition->update(['status' => 'draft']);
        [$supplier] = $this->suppliers();

        $this->post(route('purchasing.rfqs.store'), [
            'purchase_requisition_id' => $requisition->id,
            'issue_date' => '2026-09-27',
            'supplier_ids' => [$supplier->id],
        ])->assertSessionHasErrors('purchase_requisition_id');

        $this->assertSame(0, PurchaseRfq::count());
    }

    public function test_invited_supplier_quote_prices_every_requisition_line_with_exact_totals(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$requisition, $items] = $this->approvedRequisition($user);
        [$supplierA] = $this->suppliers();
        $rfq = $this->openRfq($requisition, [$supplierA], $user);

        $this->post(route('purchasing.rfqs.quotations.store', $rfq), [
            'supplier_id' => $supplierA->id,
            'supplier_reference' => 'SUP-A-77',
            'quote_date' => '2026-09-28',
            'valid_until' => '2026-10-10',
            'items' => [
                [
                    'purchase_requisition_item_id' => $items[0]->id,
                    'unit_cost' => '100.0000',
                ],
                [
                    'purchase_requisition_item_id' => $items[1]->id,
                    'unit_cost' => '20.0000',
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $quote = SupplierQuotation::with('items')->firstOrFail();

        $this->assertSame('SQT-000001', $quote->number);
        $this->assertSame('310.0000', $quote->total);
        $this->assertSame('250.0000', $quote->items[0]->line_total);
        $this->assertSame('60.0000', $quote->items[1]->line_total);
        $this->assertSame('received', $quote->status);

        $this->assertNotNull($rfq->supplierInvitations()->where('supplier_id', $supplierA->id)->firstOrFail()->responded_at);
    }

    public function test_uninvited_supplier_cannot_quote(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$requisition, $items] = $this->approvedRequisition($user);
        [$supplierA, $supplierB] = $this->suppliers();
        $rfq = $this->openRfq($requisition, [$supplierA], $user);

        $this->post(route('purchasing.rfqs.quotations.store', $rfq), [
            'supplier_id' => $supplierB->id,
            'quote_date' => '2026-09-28',
            'items' => [
                ['purchase_requisition_item_id' => $items[0]->id, 'unit_cost' => '100'],
                ['purchase_requisition_item_id' => $items[1]->id, 'unit_cost' => '20'],
            ],
        ])->assertSessionHasErrors('supplier_id');

        $this->assertSame(0, SupplierQuotation::count());
    }

    public function test_comparison_is_objective_but_award_is_explicit_even_when_higher_quote_is_selected(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$requisition, $items] = $this->approvedRequisition($user);
        [$supplierA, $supplierB] = $this->suppliers();
        $rfq = $this->openRfq($requisition, [$supplierA, $supplierB], $user);

        $quoteA = $this->recordQuote($rfq, $supplierA, $items, ['100.0000', '20.0000'], $user);
        $quoteB = $this->recordQuote($rfq, $supplierB, $items, ['105.0000', '22.0000'], $user);

        $comparison = app(RfqService::class)->comparison($rfq);
        $rowA = $comparison->firstWhere(fn (array $row): bool => $row['quotation']->id === $quoteA->id);
        $rowB = $comparison->firstWhere(fn (array $row): bool => $row['quotation']->id === $quoteB->id);

        $this->assertTrue($rowA['is_lowest']);
        $this->assertSame('0.0000', $rowA['delta_from_lowest']);
        $this->assertFalse($rowB['is_lowest']);
        $this->assertSame('18.5000', $rowB['delta_from_lowest']);
        $this->assertSame('open', $rfq->fresh()->status);
        $this->assertSame('received', $quoteA->fresh()->status);
        $this->assertSame('received', $quoteB->fresh()->status);

        $this->post(route('purchasing.rfqs.quotations.award', [$rfq, $quoteB]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('awarded', $rfq->fresh()->status);
        $this->assertSame('not_selected', $quoteA->fresh()->status);
        $this->assertSame('selected', $quoteB->fresh()->status);
        $this->assertSame($user->id, $quoteB->fresh()->selected_by);
    }

    public function test_cross_business_rfq_is_not_accessible(): void
    {
        [$userA, $businessA] = $this->context('Owner A', 'Business A');
        $this->actIn($userA, $businessA);
        [$requisition] = $this->approvedRequisition($userA);
        [$supplierA] = $this->suppliers();
        $rfq = $this->openRfq($requisition, [$supplierA], $userA);

        [$userB, $businessB] = $this->context('Owner B', 'Business B');
        $this->actIn($userB, $businessB);

        $this->post(route('purchasing.rfqs.open', $rfq))->assertNotFound();
        $this->get(route('purchasing.rfqs.index'))
            ->assertOk()
            ->assertDontSee($rfq->number);
    }

    private function approvedRequisition(User $user): array
    {
        $paper = Product::create([
            'type' => 'product',
            'name' => 'Paper Roll',
            'sku' => 'PAPER-'.Str::upper(Str::random(5)),
            'sale_price' => '0',
        ]);
        $glue = Product::create([
            'type' => 'product',
            'name' => 'Glue',
            'sku' => 'GLUE-'.Str::upper(Str::random(5)),
            'sale_price' => '0',
        ]);

        $requisition = PurchaseRequisition::create([
            'number' => 'PRQ-TEST-'.Str::upper(Str::random(5)),
            'status' => 'approved',
            'request_date' => '2026-09-27',
            'purpose' => 'Factory materials',
            'requested_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'estimated_total' => '311.8125',
        ]);

        $items = [
            $requisition->items()->create([
                'product_id' => $paper->id,
                'quantity' => '2.5000',
                'estimated_unit_cost' => '100.1250',
                'line_total' => '250.3125',
            ]),
            $requisition->items()->create([
                'product_id' => $glue->id,
                'quantity' => '3.0000',
                'estimated_unit_cost' => '20.5000',
                'line_total' => '61.5000',
            ]),
        ];

        return [$requisition, $items];
    }

    private function suppliers(): array
    {
        return [
            Supplier::create(['code' => 'SUP-A-'.Str::upper(Str::random(4)), 'name' => 'Supplier A', 'is_active' => true]),
            Supplier::create(['code' => 'SUP-B-'.Str::upper(Str::random(4)), 'name' => 'Supplier B', 'is_active' => true]),
        ];
    }

    private function openRfq(PurchaseRequisition $requisition, array $suppliers, User $user): PurchaseRfq
    {
        $rfq = app(RfqService::class)->create($requisition, [
            'issue_date' => '2026-09-27',
            'response_due_date' => '2026-10-02',
            'supplier_ids' => collect($suppliers)->pluck('id')->all(),
        ], $user->id);

        return app(RfqService::class)->open($rfq);
    }

    private function recordQuote(PurchaseRfq $rfq, Supplier $supplier, array $items, array $costs, User $user): SupplierQuotation
    {
        return app(RfqService::class)->recordQuotation($rfq, $supplier, [
            'quote_date' => '2026-09-28',
            'items' => [
                ['purchase_requisition_item_id' => $items[0]->id, 'unit_cost' => $costs[0]],
                ['purchase_requisition_item_id' => $items[1]->id, 'unit_cost' => $costs[1]],
            ],
        ], $user->id);
    }

    private function context(string $userName = 'Owner', string $businessName = 'Sourcing Co'): array
    {
        $user = User::create([
            'name' => $userName,
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('password'),
        ]);
        $business = Business::create(['name' => $businessName]);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (['dashboard', 'settings', 'products', 'purchasing'] as $module) {
            BusinessModule::updateOrCreate(
                ['business_id' => $business->id, 'module_key' => $module],
                ['enabled' => true],
            );
        }

        return [$user, $business];
    }

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();
    }
}
