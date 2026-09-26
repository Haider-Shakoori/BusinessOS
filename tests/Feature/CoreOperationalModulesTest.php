<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Bom;
use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\CrmLead;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class CoreOperationalModulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Operations Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function business(User $user): Business
    {
        $business = Business::create(['name' => 'Operations Co']);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (['dashboard', 'settings', 'products', 'inventory', 'purchasing', 'accounting', 'crm', 'manufacturing'] as $module) {
            BusinessModule::updateOrCreate(
                ['business_id' => $business->id, 'module_key' => $module],
                ['enabled' => true],
            );
        }

        return $business;
    }

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([config('business.context.session_key') => $business->id]);
        $this->app->forgetScopedInstances();
    }

    private function product(string $name, string $sku): Product
    {
        return Product::create([
            'type' => 'product',
            'name' => $name,
            'sku' => $sku,
            'sale_price' => '10.0000',
        ]);
    }

    public function test_operational_module_schema_and_registry_are_present(): void
    {
        foreach ([
            'warehouses',
            'stock_movements',
            'suppliers',
            'purchase_orders',
            'purchase_order_items',
            'accounts',
            'journal_entries',
            'journal_lines',
            'crm_leads',
            'crm_activities',
            'boms',
            'bom_items',
            'production_orders',
        ] as $table) {
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable($table));
        }

        foreach (['inventory', 'purchasing', 'accounting', 'crm', 'manufacturing'] as $module) {
            $this->assertArrayHasKey($module, config('modules.registry'));
        }
    }

    public function test_purchase_receipt_flows_into_inventory(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $product = $this->product('Paper Roll', 'PAPER-001');

        $this->post('/inventory/warehouses', [
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
        ])->assertRedirect();

        $warehouse = Warehouse::firstOrFail();

        $this->post('/purchasing/suppliers', [
            'name' => 'Paper Supplier',
            'email' => 'supplier@example.test',
        ])->assertRedirect();

        $supplier = Supplier::firstOrFail();

        $this->post('/purchasing/orders', [
            'supplier_id' => $supplier->id,
            'number' => 'PO-0001',
            'order_date' => '2026-09-26',
            'product_id' => $product->id,
            'quantity' => '12.5000',
            'unit_cost' => '7.2500',
        ])->assertRedirect();

        $order = PurchaseOrder::firstOrFail();
        $this->assertSame('draft', $order->status);
        $this->assertSame('90.6250', $order->total);

        $this->post('/purchasing/orders/'.$order->id.'/receive', [
            'warehouse_id' => $warehouse->id,
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('received', $order->status);
        $this->assertDatabaseHas('stock_movements', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'purchase',
            'quantity' => 12.5,
        ]);

        $this->post('/purchasing/orders/'.$order->id.'/receive', [
            'warehouse_id' => $warehouse->id,
        ])->assertRedirect();

        $this->assertSame(1, StockMovement::query()->where('reference_type', PurchaseOrder::class)->count());
    }

    public function test_accounting_posts_balanced_double_entry(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $this->post('/accounting/accounts', [
            'code' => '1000',
            'name' => 'Cash',
            'type' => 'asset',
        ])->assertRedirect();

        $this->post('/accounting/accounts', [
            'code' => '3000',
            'name' => 'Owner Equity',
            'type' => 'equity',
        ])->assertRedirect();

        $cash = Account::where('code', '1000')->firstOrFail();
        $equity = Account::where('code', '3000')->firstOrFail();

        $this->post('/accounting/journals', [
            'number' => 'JV-0001',
            'entry_date' => '2026-09-26',
            'description' => 'Opening capital',
            'debit_account_id' => $cash->id,
            'credit_account_id' => $equity->id,
            'amount' => '50000.0000',
        ])->assertRedirect();

        $entry = JournalEntry::with('lines')->firstOrFail();
        $this->assertCount(2, $entry->lines);
        $this->assertSame('50000.0000', $entry->lines->sum(fn ($line) => (float) $line->debit) > 0 ? number_format($entry->lines->sum(fn ($line) => (float) $line->debit), 4, '.', '') : '0.0000');
        $this->assertEquals(
            $entry->lines->sum(fn ($line) => (float) $line->debit),
            $entry->lines->sum(fn ($line) => (float) $line->credit),
        );
    }

    public function test_crm_lead_accepts_auditable_activity(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $this->post('/crm/leads', [
            'name' => 'Potential Customer',
            'company' => 'Prospect Co',
            'status' => 'qualified',
            'estimated_value' => '15000.0000',
        ])->assertRedirect();

        $lead = CrmLead::firstOrFail();

        $this->post('/crm/leads/'.$lead->id.'/activities', [
            'type' => 'call',
            'note' => 'Discussed requirements.',
        ])->assertRedirect();

        $this->assertDatabaseHas('crm_activities', [
            'crm_lead_id' => $lead->id,
            'type' => 'call',
            'note' => 'Discussed requirements.',
        ]);
    }

    public function test_production_completion_consumes_bom_and_adds_finished_stock(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $raw = $this->product('Raw Material', 'RAW-001');
        $finished = $this->product('Finished Good', 'FG-001');
        $warehouse = Warehouse::create(['code' => 'PROD', 'name' => 'Production Warehouse', 'is_active' => true]);

        $this->post('/manufacturing/boms', [
            'product_id' => $finished->id,
            'code' => 'BOM-FG-001',
            'version' => '1',
            'material_product_id' => $raw->id,
            'quantity' => '2.0000',
            'wastage_percent' => '5.0000',
        ])->assertRedirect();

        $bom = Bom::firstOrFail();

        $this->post('/manufacturing/orders', [
            'bom_id' => $bom->id,
            'product_id' => $finished->id,
            'number' => 'MO-0001',
            'planned_quantity' => '10.0000',
            'start_date' => '2026-09-26',
        ])->assertRedirect();

        $order = ProductionOrder::firstOrFail();

        $this->post('/manufacturing/orders/'.$order->id.'/complete', [
            'warehouse_id' => $warehouse->id,
            'actual_quantity' => '10.0000',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame('10.0000', $order->actual_quantity);

        $this->assertSame(
            -21.0,
            (float) StockMovement::where('product_id', $raw->id)->where('type', 'production_out')->sum('quantity'),
        );
        $this->assertSame(
            10.0,
            (float) StockMovement::where('product_id', $finished->id)->where('type', 'production_in')->sum('quantity'),
        );
    }

    public function test_cross_business_product_cannot_be_used_in_inventory_write(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main', 'is_active' => true]);

        $otherUser = $this->user();
        $otherBusiness = $this->business($otherUser);
        $this->actIn($otherUser, $otherBusiness);
        $foreignProduct = $this->product('Foreign Product', 'FOREIGN-1');

        $this->actIn($user, $business);

        $this->post('/inventory/movements', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $foreignProduct->id,
            'type' => 'opening',
            'quantity' => '1',
        ])->assertSessionHasErrors('product_id');

        $this->assertSame(0, StockMovement::count());
    }
}
