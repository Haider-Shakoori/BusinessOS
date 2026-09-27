<?php

namespace Tests\Feature;

use App\Models\AccountingPosting;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SupplierInvoiceService;
use App\Services\SupplierLedgerService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierInvoiceMatchingTest extends TestCase
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

    public function test_supplier_invoice_schema_and_workspace_exist(): void
    {
        $this->assertTrue(Schema::hasTable('supplier_invoices'));
        $this->assertTrue(Schema::hasTable('supplier_invoice_items'));

        [$user, $business] = $this->context();
        $this->actIn($user, $business);

        $this->get(route('purchasing.supplier-invoices.index'))
            ->assertOk()
            ->assertSee('Supplier invoices');
    }

    public function test_exact_match_invoice_can_be_created_submitted_and_approved_without_duplicate_ap(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$order, $supplier] = $this->receivedOrder();

        $payableBefore = app(SupplierLedgerService::class)->outstandingBalance($supplier);
        $postingCountBefore = AccountingPosting::count();

        $this->post(route('purchasing.supplier-invoices.store'), [
            'purchase_order_id' => $order->id,
            'supplier_invoice_number' => 'SUP-BILL-1001',
            'invoice_date' => '2026-09-30',
            'due_date' => '2026-10-15',
            'items' => [
                [
                    'purchase_order_item_id' => $order->items[0]->id,
                    'quantity' => '1.5000',
                    'unit_cost' => '100.0000',
                ],
                [
                    'purchase_order_item_id' => $order->items[1]->id,
                    'quantity' => '0',
                    'unit_cost' => '20.0000',
                ],
            ],
        ])->assertRedirect(route('purchasing.supplier-invoices.index'))
            ->assertSessionHasNoErrors();

        $invoice = SupplierInvoice::with('items')->firstOrFail();

        $this->assertSame('SINV-000001', $invoice->number);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('matched', $invoice->match_status);
        $this->assertSame('150.0000', $invoice->total);
        $this->assertSame('150.0000', $invoice->po_basis_total);
        $this->assertSame('0.0000', $invoice->price_variance_total);
        $this->assertCount(1, $invoice->items);

        $this->post(route('purchasing.supplier-invoices.submit', $invoice))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->post(route('purchasing.supplier-invoices.approve', $invoice->fresh()))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $invoice->refresh();

        $this->assertSame('approved', $invoice->status);
        $this->assertSame($user->id, $invoice->approved_by);
        $this->assertNull($invoice->match_override_reason);

        $this->assertSame($payableBefore, app(SupplierLedgerService::class)->outstandingBalance($supplier));
        $this->assertSame($postingCountBefore, AccountingPosting::count());
        $this->assertSame(
            0,
            AccountingPosting::query()
                ->where('source_type', SupplierInvoice::class)
                ->where('source_id', $invoice->id)
                ->count(),
        );
    }

    public function test_price_variance_requires_audited_override_reason_before_approval(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$order] = $this->receivedOrder();

        $this->post(route('purchasing.supplier-invoices.store'), [
            'purchase_order_id' => $order->id,
            'supplier_invoice_number' => 'SUP-BILL-VAR',
            'invoice_date' => '2026-09-30',
            'items' => [
                [
                    'purchase_order_item_id' => $order->items[0]->id,
                    'quantity' => '1.0000',
                    'unit_cost' => '105.0000',
                ],
            ],
        ])->assertSessionHasNoErrors();

        $invoice = SupplierInvoice::firstOrFail();

        $this->assertSame('price_variance', $invoice->match_status);
        $this->assertSame('105.0000', $invoice->total);
        $this->assertSame('100.0000', $invoice->po_basis_total);
        $this->assertSame('5.0000', $invoice->price_variance_total);

        $this->post(route('purchasing.supplier-invoices.submit', $invoice))
            ->assertSessionHasNoErrors();

        $this->post(route('purchasing.supplier-invoices.approve', $invoice->fresh()))
            ->assertSessionHasErrors('match_override_reason');

        $this->post(route('purchasing.supplier-invoices.approve', $invoice->fresh()), [
            'match_override_reason' => 'Supplier confirmed market price increase after RFQ validity window.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $invoice->refresh();

        $this->assertSame('approved', $invoice->status);
        $this->assertSame($user->id, $invoice->match_override_by);
        $this->assertNotNull($invoice->match_override_at);
        $this->assertSame(
            'Supplier confirmed market price increase after RFQ validity window.',
            $invoice->match_override_reason,
        );
    }

    public function test_invoice_quantity_cannot_exceed_received_quantity(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$order] = $this->receivedOrder(['1.0000', '0.0000']);

        $this->post(route('purchasing.supplier-invoices.store'), [
            'purchase_order_id' => $order->id,
            'supplier_invoice_number' => 'SUP-BILL-OVER',
            'invoice_date' => '2026-09-30',
            'items' => [
                [
                    'purchase_order_item_id' => $order->items[0]->id,
                    'quantity' => '1.1000',
                    'unit_cost' => '100.0000',
                ],
            ],
        ])->assertSessionHasErrors('items');

        $this->assertSame(0, SupplierInvoice::count());
    }

    public function test_submitted_invoice_reserves_received_quantity_and_rejection_releases_it(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$order] = $this->receivedOrder(['1.0000', '0.0000']);

        $invoiceA = app(SupplierInvoiceService::class)->create($order, [
            'supplier_invoice_number' => 'SUP-BILL-A',
            'invoice_date' => '2026-09-30',
            'items' => [
                [
                    'purchase_order_item_id' => $order->items[0]->id,
                    'quantity' => '1.0000',
                    'unit_cost' => '100.0000',
                ],
            ],
        ], $user->id);

        app(SupplierInvoiceService::class)->submit($invoiceA, $user->id);

        $this->post(route('purchasing.supplier-invoices.store'), [
            'purchase_order_id' => $order->id,
            'supplier_invoice_number' => 'SUP-BILL-B',
            'invoice_date' => '2026-09-30',
            'items' => [
                [
                    'purchase_order_item_id' => $order->items[0]->id,
                    'quantity' => '1.0000',
                    'unit_cost' => '100.0000',
                ],
            ],
        ])->assertSessionHasErrors('items');

        app(SupplierInvoiceService::class)->reject($invoiceA->fresh(), $user->id, 'Wrong supplier document.');

        $this->post(route('purchasing.supplier-invoices.store'), [
            'purchase_order_id' => $order->id,
            'supplier_invoice_number' => 'SUP-BILL-B',
            'invoice_date' => '2026-09-30',
            'items' => [
                [
                    'purchase_order_item_id' => $order->items[0]->id,
                    'quantity' => '1.0000',
                    'unit_cost' => '100.0000',
                ],
            ],
        ])->assertRedirect(route('purchasing.supplier-invoices.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, SupplierInvoice::count());
    }

    public function test_supplier_invoice_reference_is_unique_per_supplier(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);
        [$order] = $this->receivedOrder();

        $payload = [
            'purchase_order_id' => $order->id,
            'supplier_invoice_number' => 'DUP-900',
            'invoice_date' => '2026-09-30',
            'items' => [
                [
                    'purchase_order_item_id' => $order->items[0]->id,
                    'quantity' => '0.5000',
                    'unit_cost' => '100.0000',
                ],
            ],
        ];

        $this->post(route('purchasing.supplier-invoices.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->post(route('purchasing.supplier-invoices.store'), $payload)
            ->assertSessionHasErrors('supplier_invoice_number');

        $this->assertSame(1, SupplierInvoice::count());
    }

    public function test_legacy_received_po_without_grn_can_be_matched(): void
    {
        [$user, $business] = $this->context();
        $this->actIn($user, $business);

        $supplier = Supplier::create([
            'code' => 'LEGACY-SUP',
            'name' => 'Legacy Supplier',
            'is_active' => true,
        ]);
        $product = Product::create([
            'type' => 'product',
            'name' => 'Legacy Item',
            'sku' => 'LEGACY-'.Str::upper(Str::random(5)),
            'sale_price' => '0',
        ]);
        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'number' => 'LEGACY-PO-1',
            'status' => 'received',
            'order_date' => '2026-09-01',
            'subtotal' => '50.0000',
            'total' => '50.0000',
        ]);
        $item = $order->items()->create([
            'product_id' => $product->id,
            'quantity' => '5.0000',
            'unit_cost' => '10.0000',
            'line_total' => '50.0000',
        ]);

        $available = app(SupplierInvoiceService::class)->availableQuantities($order->load('items'));

        $this->assertSame('5.0000', $available->get($item->id));

        $this->post(route('purchasing.supplier-invoices.store'), [
            'purchase_order_id' => $order->id,
            'supplier_invoice_number' => 'LEGACY-BILL-1',
            'invoice_date' => '2026-09-02',
            'items' => [
                [
                    'purchase_order_item_id' => $item->id,
                    'quantity' => '5.0000',
                    'unit_cost' => '10.0000',
                ],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame('matched', SupplierInvoice::firstOrFail()->match_status);
    }

    public function test_cross_business_supplier_invoice_is_not_accessible(): void
    {
        [$userA, $businessA] = $this->context('Owner A', 'Business A');
        $this->actIn($userA, $businessA);
        [$order] = $this->receivedOrder();

        $invoice = app(SupplierInvoiceService::class)->create($order, [
            'supplier_invoice_number' => 'A-BILL-1',
            'invoice_date' => '2026-09-30',
            'items' => [
                [
                    'purchase_order_item_id' => $order->items[0]->id,
                    'quantity' => '1.0000',
                    'unit_cost' => '100.0000',
                ],
            ],
        ], $userA->id);

        [$userB, $businessB] = $this->context('Owner B', 'Business B');
        $this->actIn($userB, $businessB);

        $this->post(route('purchasing.supplier-invoices.submit', $invoice))
            ->assertNotFound();

        $this->get(route('purchasing.supplier-invoices.index'))
            ->assertOk()
            ->assertDontSee($invoice->number);
    }

    private function receivedOrder(?array $receiptQuantities = null): array
    {
        $supplier = Supplier::create([
            'code' => 'SUP-'.Str::upper(Str::random(5)),
            'name' => 'Invoice Supplier',
            'is_active' => true,
        ]);
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

        $order = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'number' => 'PO-TEST-'.Str::upper(Str::random(5)),
            'status' => 'ordered',
            'order_date' => '2026-09-28',
            'expected_date' => '2026-10-05',
            'subtotal' => '310.0000',
            'total' => '310.0000',
        ]);

        $items = [
            $order->items()->create([
                'product_id' => $paper->id,
                'quantity' => '2.5000',
                'unit_cost' => '100.0000',
                'line_total' => '250.0000',
            ]),
            $order->items()->create([
                'product_id' => $glue->id,
                'quantity' => '3.0000',
                'unit_cost' => '20.0000',
                'line_total' => '60.0000',
            ]),
        ];

        $warehouse = Warehouse::create([
            'code' => 'MAIN-'.Str::upper(Str::random(4)),
            'name' => 'Main Warehouse',
            'is_active' => true,
        ]);

        $payload = [
            'warehouse_id' => $warehouse->id,
            'receipt_date' => '2026-09-29',
        ];

        if ($receiptQuantities !== null) {
            $payload['items'] = [
                [
                    'purchase_order_item_id' => $items[0]->id,
                    'quantity' => $receiptQuantities[0],
                ],
                [
                    'purchase_order_item_id' => $items[1]->id,
                    'quantity' => $receiptQuantities[1],
                ],
            ];
        }

        $this->post(route('purchasing.orders.receive', $order), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return [$order->fresh()->load('items'), $supplier, $warehouse];
    }

    private function context(string $userName = 'Owner', string $businessName = 'Supplier Invoice Co'): array
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

        foreach (['dashboard', 'settings', 'products', 'inventory', 'purchasing', 'accounting'] as $module) {
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
