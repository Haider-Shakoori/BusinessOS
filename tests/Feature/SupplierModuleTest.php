<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\InventoryReturn;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ImportService;
use App\Services\SupplierLedgerService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
        Storage::fake('local');
    }

    private function user(string $name = 'Supplier Owner'): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function business(User $user, string $name = 'Supplier Co', string $role = 'owner'): Business
    {
        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles[$role]);

        foreach (['dashboard', 'settings', 'products', 'inventory', 'purchasing'] as $module) {
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

    private function tokenFrom(string $location): string
    {
        return (string) basename((string) parse_url($location, PHP_URL_PATH));
    }

    public function test_supplier_schema_permissions_and_import_support_exist(): void
    {
        foreach (['code', 'opening_balance', 'opening_balance_date', 'notes'] as $column) {
            $this->assertTrue(Schema::hasColumn('suppliers', $column));
        }

        $this->assertContains('suppliers.view', config('permissions.groups.suppliers'));
        $this->assertContains('suppliers.manage', config('permissions.groups.suppliers'));
        $this->assertTrue(app(ImportService::class)->supports('suppliers'));
    }

    public function test_owner_can_create_edit_and_view_supplier_with_generated_code(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $this->get('/suppliers')->assertOk()->assertSee('Suppliers');

        $response = $this->post('/suppliers', [
            'name' => 'Kabul Paper Supply',
            'email' => 'paper@example.test',
            'phone' => '+93 700 000 001',
            'address' => 'Kabul',
            'opening_balance' => '125.5000',
            'opening_balance_date' => '2026-09-01',
            'notes' => 'Primary paper supplier',
        ]);

        $supplier = Supplier::firstOrFail();

        $response->assertRedirect(route('suppliers.show', $supplier));
        $this->assertSame('SUP-000001', $supplier->code);
        $this->assertSame('125.5000', $supplier->opening_balance);

        $this->patch('/suppliers/'.$supplier->id, [
            'code' => 'PAPER-01',
            'name' => 'Kabul Paper Supply Ltd',
            'opening_balance' => '125.5000',
            'opening_balance_date' => '2026-09-01',
        ])->assertRedirect(route('suppliers.show', $supplier));

        $supplier->refresh();
        $this->assertSame('PAPER-01', $supplier->code);
        $this->assertSame('Kabul Paper Supply Ltd', $supplier->name);

        $this->get('/suppliers/'.$supplier->id)
            ->assertOk()
            ->assertSee('PAPER-01')
            ->assertSee('Kabul Paper Supply Ltd');
    }

    public function test_supplier_payable_tracks_purchase_return_payment_and_reversal(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $supplier = Supplier::create([
            'code' => 'SUP-LEDGER',
            'name' => 'Ledger Supplier',
            'opening_balance' => '100.0000',
            'opening_balance_date' => '2026-09-01',
            'is_active' => true,
        ]);
        $warehouse = Warehouse::create(['code' => 'MAIN', 'name' => 'Main Warehouse', 'is_active' => true]);
        $product = Product::create([
            'type' => 'product',
            'name' => 'Raw Material',
            'sku' => 'RAW-SUP',
            'sale_price' => '20.0000',
        ]);

        $this->post('/purchasing/orders', [
            'supplier_id' => $supplier->id,
            'number' => 'PO-SUP-001',
            'order_date' => '2026-09-10',
            'product_id' => $product->id,
            'quantity' => '10.0000',
            'unit_cost' => '100.0000',
        ])->assertRedirect();

        $order = PurchaseOrder::with('items')->firstOrFail();

        $this->post('/purchasing/orders/'.$order->id.'/receive', [
            'warehouse_id' => $warehouse->id,
        ])->assertRedirect();

        $item = $order->items->firstOrFail();

        $this->post('/inventory/returns/purchases', [
            'purchase_order_item_id' => $item->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => '2.0000',
            'reason' => 'Two units damaged',
        ])->assertRedirect();

        $return = InventoryReturn::firstOrFail();
        $this->assertSame('200.0000', $return->total);

        $summary = app(SupplierLedgerService::class)->summary($supplier->refresh());
        $this->assertSame('100.0000', $summary['opening_balance']);
        $this->assertSame('1000.0000', $summary['total_purchased']);
        $this->assertSame('200.0000', $summary['total_returned']);
        $this->assertSame('0.0000', $summary['total_paid']);
        $this->assertSame('900.0000', $summary['outstanding_balance']);

        $this->post('/suppliers/'.$supplier->id.'/payments', [
            'amount' => '300.0000',
            'payment_method' => 'bank_transfer',
            'payment_date' => '2026-09-15',
            'reference' => 'BANK-001',
        ])->assertRedirect(route('suppliers.show', $supplier));

        $payment = Payment::where('party_type', 'supplier')->firstOrFail();
        $this->assertSame('PAY-000001', $payment->payment_number);
        $this->assertSame($supplier->id, $payment->party_id);
        $this->assertDatabaseCount('payment_allocations', 0);

        $summary = app(SupplierLedgerService::class)->summary($supplier);
        $this->assertSame('300.0000', $summary['total_paid']);
        $this->assertSame('600.0000', $summary['outstanding_balance']);

        $this->post('/suppliers/'.$supplier->id.'/payments/'.$payment->id.'/reverse', [
            'reversal_reason' => 'Bank transfer cancelled',
        ])->assertRedirect(route('suppliers.show', $supplier));

        $payment->refresh();
        $this->assertNotNull($payment->reversed_at);

        $summary = app(SupplierLedgerService::class)->summary($supplier);
        $this->assertSame('0.0000', $summary['total_paid']);
        $this->assertSame('900.0000', $summary['outstanding_balance']);

        $this->get('/suppliers/'.$supplier->id.'/ledger')
            ->assertOk()
            ->assertSee('PO-SUP-001')
            ->assertSee('BANK-001');
    }

    public function test_supplier_payment_cannot_exceed_current_payable(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $supplier = Supplier::create([
            'code' => 'SUP-LIMIT',
            'name' => 'Limited Supplier',
            'opening_balance' => '50.0000',
            'is_active' => true,
        ]);

        $this->post('/suppliers/'.$supplier->id.'/payments', [
            'amount' => '50.0001',
            'payment_method' => 'cash',
            'payment_date' => '2026-09-26',
        ])->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('payments', 0);
    }

    public function test_supplier_with_financial_history_cannot_be_deleted(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $supplier = Supplier::create([
            'code' => 'SUP-HISTORY',
            'name' => 'Historical Supplier',
            'opening_balance' => '25.0000',
            'is_active' => true,
        ]);

        $this->post('/suppliers/'.$supplier->id.'/payments', [
            'amount' => '10.0000',
            'payment_method' => 'cash',
            'payment_date' => '2026-09-26',
        ])->assertRedirect();

        $this->delete('/suppliers/'.$supplier->id)
            ->assertSessionHasErrors('supplier');

        $this->assertNotNull(Supplier::find($supplier->id));
    }

    public function test_supplier_csv_import_creates_records_and_rejects_duplicate_codes_in_file(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $csv = "code,name,email,phone,address,opening_balance,opening_balance_date,notes\n"
            ."S001,Supplier One,one@example.test,+9301,Kabul,10.5,2026-09-01,First\n"
            .",Supplier Two,,,,0,,Second\n";

        $location = $this->post('/suppliers/import', [
            'file' => UploadedFile::fake()->createWithContent('suppliers.csv', $csv),
        ])->assertSessionHasNoErrors(['file'])->headers->get('Location');

        $token = $this->tokenFrom((string) $location);

        $this->get('/suppliers/import/preview/'.$token)
            ->assertOk()
            ->assertSee('Supplier One')
            ->assertSee('Supplier Two');

        $this->post('/suppliers/import/preview/'.$token)
            ->assertRedirect(route('suppliers.index'));

        $suppliers = Supplier::orderBy('id')->get();
        $this->assertCount(2, $suppliers);
        $this->assertSame('S001', $suppliers[0]->code);
        $this->assertSame('10.5000', $suppliers[0]->opening_balance);
        $this->assertSame('SUP-000002', $suppliers[1]->code);

        $duplicateCsv = "code,name\nDUP,Dup One\nDUP,Dup Two\n";
        $duplicateLocation = $this->post('/suppliers/import', [
            'file' => UploadedFile::fake()->createWithContent('suppliers.csv', $duplicateCsv),
        ])->headers->get('Location');

        $duplicateToken = $this->tokenFrom((string) $duplicateLocation);

        $this->get('/suppliers/import/preview/'.$duplicateToken)
            ->assertOk()
            ->assertSee('Duplicate supplier code');

        $this->assertSame(2, Supplier::count());
    }

    public function test_cross_business_supplier_is_not_accessible(): void
    {
        $ownerA = $this->user('Owner A');
        $businessA = $this->business($ownerA, 'Business A');
        $this->actIn($ownerA, $businessA);

        $supplier = Supplier::create([
            'code' => 'A-SUP',
            'name' => 'Business A Supplier',
            'is_active' => true,
        ]);

        $ownerB = $this->user('Owner B');
        $businessB = $this->business($ownerB, 'Business B');
        $this->actIn($ownerB, $businessB);

        $this->get('/suppliers/'.$supplier->id)->assertNotFound();
        $this->post('/suppliers/'.$supplier->id.'/payments', [
            'amount' => '1',
            'payment_method' => 'cash',
            'payment_date' => '2026-09-26',
        ])->assertNotFound();
    }

    public function test_statement_carries_balance_forward_for_filtered_period(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $supplier = Supplier::create([
            'code' => 'SUP-STMT',
            'name' => 'Statement Supplier',
            'opening_balance' => '100.0000',
            'opening_balance_date' => '2026-08-01',
            'is_active' => true,
        ]);

        $result = app(SupplierLedgerService::class)->ledger($supplier, [
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
        ]);

        $this->assertSame('100.0000', $result['brought_forward']);
        $this->assertSame('100.0000', $result['closing_balance']);
        $this->assertSame('brought_forward', $result['rows'][0]['type']);
    }
}
