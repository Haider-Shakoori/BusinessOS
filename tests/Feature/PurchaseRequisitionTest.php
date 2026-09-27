<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\Product;
use App\Models\PurchaseRequisition;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseRequisitionTest extends TestCase
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

    public function test_purchase_requisition_schema_and_workspace_exist(): void
    {
        $this->assertTrue(Schema::hasTable('purchase_requisitions'));
        $this->assertTrue(Schema::hasTable('purchase_requisition_items'));

        [$user, $business] = $this->context();
        $this->actIn($user, $business);

        $this->get(route('purchasing.requisitions.index'))
            ->assertOk()
            ->assertSee('Purchase requisitions');
    }

    public function test_owner_can_create_multiline_requisition_with_exact_total_and_number(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);

        $paper = Product::create([
            'type' => 'product',
            'name' => 'Paper Roll',
            'sku' => 'PAPER-001',
            'sale_price' => '0',
        ]);
        $glue = Product::create([
            'type' => 'product',
            'name' => 'Glue',
            'sku' => 'GLUE-001',
            'sale_price' => '0',
        ]);

        $this->post(route('purchasing.requisitions.store'), [
            'request_date' => '2026-09-27',
            'needed_by' => '2026-10-05',
            'purpose' => 'October raw materials',
            'items' => [
                [
                    'product_id' => $paper->id,
                    'quantity' => '2.5000',
                    'estimated_unit_cost' => '100.1250',
                    'description' => 'Main liner paper',
                ],
                [
                    'product_id' => $glue->id,
                    'quantity' => '3.0000',
                    'estimated_unit_cost' => '20.5000',
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $requisition = PurchaseRequisition::with('items')->firstOrFail();

        $this->assertSame('PRQ-000001', $requisition->number);
        $this->assertSame('draft', $requisition->status);
        $this->assertSame($user->id, $requisition->requested_by);
        $this->assertSame('311.8125', $requisition->estimated_total);
        $this->assertCount(2, $requisition->items);
        $this->assertSame('250.3125', $requisition->items[0]->line_total);
        $this->assertSame('61.5000', $requisition->items[1]->line_total);
    }

    public function test_submitted_requisition_can_be_approved_once(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);

        $requisition = $this->createDraftRequisition();

        $this->post(route('purchasing.requisitions.submit', $requisition))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $requisition->refresh();
        $this->assertSame('submitted', $requisition->status);
        $this->assertNotNull($requisition->submitted_at);

        $this->post(route('purchasing.requisitions.approve', $requisition))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $requisition->refresh();
        $this->assertSame('approved', $requisition->status);
        $this->assertSame($user->id, $requisition->approved_by);
        $this->assertNotNull($requisition->approved_at);

        $this->post(route('purchasing.requisitions.approve', $requisition))
            ->assertSessionHasErrors('status');
    }

    public function test_submitted_requisition_requires_reason_when_rejected(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);

        $requisition = $this->createDraftRequisition();
        $this->post(route('purchasing.requisitions.submit', $requisition))->assertRedirect();

        $this->post(route('purchasing.requisitions.reject', $requisition), [])
            ->assertSessionHasErrors('rejection_reason');

        $this->post(route('purchasing.requisitions.reject', $requisition), [
            'rejection_reason' => 'Budget not approved.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $requisition->refresh();
        $this->assertSame('rejected', $requisition->status);
        $this->assertSame($user->id, $requisition->rejected_by);
        $this->assertSame('Budget not approved.', $requisition->rejection_reason);
        $this->assertNotNull($requisition->rejected_at);
    }

    public function test_requisitions_and_products_are_isolated_between_businesses(): void
    {
        [$userA, $businessA] = $this->context('Owner A', 'Business A');
        $this->actIn($userA, $businessA);

        $productA = Product::create([
            'type' => 'product',
            'name' => 'Business A Product',
            'sku' => 'A-001',
            'sale_price' => '0',
        ]);
        $requisitionA = $this->createDraftRequisition($productA);

        [$userB, $businessB] = $this->context('Owner B', 'Business B');
        $this->actIn($userB, $businessB);

        $this->get(route('purchasing.requisitions.index'))
            ->assertOk()
            ->assertDontSee($requisitionA->number);

        $this->post(route('purchasing.requisitions.submit', $requisitionA))
            ->assertNotFound();

        $this->post(route('purchasing.requisitions.store'), [
            'request_date' => '2026-09-27',
            'items' => [[
                'product_id' => $productA->id,
                'quantity' => '1',
                'estimated_unit_cost' => '10',
            ]],
        ])->assertSessionHasErrors('items.0.product_id');

        $this->assertSame(0, PurchaseRequisition::count());
    }

    private function createDraftRequisition(?Product $product = null): PurchaseRequisition
    {
        $product ??= Product::create([
            'type' => 'product',
            'name' => 'Requested Product',
            'sku' => Str::upper(Str::random(8)),
            'sale_price' => '0',
        ]);

        $this->post(route('purchasing.requisitions.store'), [
            'request_date' => '2026-09-27',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => '1.0000',
                'estimated_unit_cost' => '10.0000',
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return PurchaseRequisition::query()->latest('id')->firstOrFail();
    }

    private function context(string $userName = 'Owner', string $businessName = 'Procurement Co'): array
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
