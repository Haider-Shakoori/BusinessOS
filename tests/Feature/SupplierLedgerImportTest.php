<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\InventoryReturn;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ImportService;
use App\Services\SupplierLedgerService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierLedgerImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
            'email' => Str::random(12).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function business(User $user, string $name = 'Supplier Co'): Business
    {
        $business = Business::create(['name' => $name]);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();

        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (['purchasing', 'inventory'] as $module) {
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

    private function supplier(array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'code' => 'SUP-001',
            'name' => 'Main Supplier',
            'email' => 'supplier@example.test',
            'opening_balance' => '100.0000',
            'opening_balance_date' => '2026-09-01',
            'is_active' => true,
        ], $overrides));
    }

    public function test_supplier_csv_import_creates_business_scoped_ledger_fields(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $csv = "code,name,email,phone,address,opening_balance,opening_balance_date,notes\n"
            ."SUP-CSV-1,Imported Supplier,imported@example.test,+93 700 000000,Kabul,250.5000,2026-09-05,Opening supplier\n";

        $location = $this->post('/suppliers/import', [
            'file' => UploadedFile::fake()->createWithContent('suppliers.csv', $csv),
        ])->assertSessionHasNoErrors(['file'])->headers->get('Location');

        $this->assertNotNull($location);
        $token = basename((string) parse_url((string) $location, PHP_URL_PATH));

        $this->get('/suppliers/import/preview/'.$token)
            ->assertOk()
            ->assertSee('Imported Supplier')
            ->assertSee(__('imports.confirm'));

        $this->post('/suppliers/import/preview/'.$token)
            ->assertRedirect(route('suppliers.index'))
            ->assertSessionHas('status');

        $supplier = Supplier::where('code', 'SUP-CSV-1')->firstOrFail();

        $this->assertSame($business->id, $supplier->business_id);
        $this->assertSame('Imported Supplier', $supplier->name);
        $this->assertSame('250.5000', $supplier->opening_balance);
        $this->assertSame('2026-09-05', $supplier->opening_balance_date?->toDateString());
        $this->assertEmpty(Storage::disk('local')->allFiles('imports'));
        $this->assertEmpty(session(ImportService::TOKEN_SESSION_KEY) ?? []);
    }

    public function test_supplier_ledger_accounts_for_purchase_return_payment_and_reversal(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $supplier = $this->supplier();
        $warehouse = Warehouse::create([
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'is_active' => true,
        ]);

        $purchase = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'number' => 'PO-SUP-001',
            'status' => 'received',
            'order_date' => '2026-09-10',
            'subtotal' => '500.0000',
            'total' => '500.0000',
        ]);

        InventoryReturn::create([
            'warehouse_id' => $warehouse->id,
            'processed_by' => $user->id,
            'number' => 'RET-SUP-001',
            'type' => 'purchase',
            'source_type' => PurchaseOrder::class,
            'source_id' => $purchase->id,
            'status' => 'completed',
            'subtotal' => '50.0000',
            'tax_amount' => '0.0000',
            'total' => '50.0000',
            'reason' => 'Damaged material',
            'processed_at' => '2026-09-12 10:00:00',
        ]);

        $this->post('/suppliers/'.$supplier->id.'/payments', [
            'purchase_order_id' => $purchase->id,
            'payment_date' => '2026-09-15',
            'amount' => '200.0000',
            'payment_method' => 'cash',
            'reference' => 'CASH-1',
        ])->assertRedirect(route('suppliers.show', $supplier));

        $service = app(SupplierLedgerService::class);
        $summary = $service->summary($supplier);

        $this->assertSame('100.0000', $summary['opening_balance']);
        $this->assertSame('500.0000', $summary['total_purchases']);
        $this->assertSame('50.0000', $summary['total_returns']);
        $this->assertSame('200.0000', $summary['total_paid']);
        $this->assertSame('350.0000', $summary['outstanding_balance']);

        $payment = Payment::where('party_type', 'supplier')->where('party_id', $supplier->id)->firstOrFail();

        $this->post('/suppliers/'.$supplier->id.'/payments/'.$payment->id.'/reverse', [
            'reversal_reason' => 'Payment entered twice',
        ])->assertRedirect(route('suppliers.show', $supplier));

        $summary = $service->summary($supplier->fresh());
        $this->assertSame('0.0000', $summary['total_paid']);
        $this->assertSame('550.0000', $summary['outstanding_balance']);

        $types = array_column($service->ledger($supplier->fresh())['rows'], 'type');
        $this->assertContains('opening', $types);
        $this->assertContains('purchase', $types);
        $this->assertContains('return', $types);
        $this->assertContains('payment', $types);
        $this->assertContains('reversal', $types);
    }

    public function test_supplier_routes_and_statement_are_tenant_scoped(): void
    {
        $user = $this->user();
        $businessA = $this->business($user, 'Supplier A');
        $businessB = $this->business($user, 'Supplier B');

        $this->actIn($user, $businessA);
        $supplierA = $this->supplier(['code' => 'A-001', 'name' => 'Supplier A']);

        $this->get('/suppliers/'.$supplierA->id)
            ->assertOk()
            ->assertSee('Supplier A');

        $this->get('/suppliers/'.$supplierA->id.'/ledger')
            ->assertOk()
            ->assertSee(__('suppliers.ledger.title'));

        $this->get('/suppliers/'.$supplierA->id.'/statement')
            ->assertOk()
            ->assertSee(__('suppliers.statement.title'));

        $this->actIn($user, $businessB);

        $this->get('/suppliers/'.$supplierA->id)->assertNotFound();
        $this->get('/suppliers/'.$supplierA->id.'/ledger')->assertNotFound();
        $this->get('/suppliers/'.$supplierA->id.'/statement')->assertNotFound();
    }

    public function test_supplier_payment_cannot_reference_another_suppliers_purchase(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $supplierA = $this->supplier(['code' => 'SUP-A', 'name' => 'Supplier A']);
        $supplierB = $this->supplier(['code' => 'SUP-B', 'name' => 'Supplier B']);

        $foreignPurchase = PurchaseOrder::create([
            'supplier_id' => $supplierB->id,
            'number' => 'PO-B-001',
            'status' => 'received',
            'order_date' => '2026-09-20',
            'subtotal' => '100.0000',
            'total' => '100.0000',
        ]);

        $this->post('/suppliers/'.$supplierA->id.'/payments', [
            'purchase_order_id' => $foreignPurchase->id,
            'payment_date' => '2026-09-21',
            'amount' => '20.0000',
            'payment_method' => 'cash',
        ])->assertSessionHasErrors('purchase_order_id');

        $this->assertSame(0, Payment::where('party_type', 'supplier')->count());
    }

    public function test_duplicate_supplier_code_in_csv_is_rejected_before_write(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        $this->actIn($user, $business);

        $csv = "code,name,email,phone,address,opening_balance,opening_balance_date,notes\n"
            ."DUP-1,First,,,,0,,\n"
            ."DUP-1,Second,,,,0,,\n";

        $location = $this->post('/suppliers/import', [
            'file' => UploadedFile::fake()->createWithContent('suppliers.csv', $csv),
        ])->headers->get('Location');

        $token = basename((string) parse_url((string) $location, PHP_URL_PATH));

        $this->get('/suppliers/import/preview/'.$token)
            ->assertOk()
            ->assertSee(__('suppliers.validation.code_duplicate_file'))
            ->assertDontSee(__('imports.confirm'));

        $this->post('/suppliers/import/preview/'.$token)
            ->assertRedirect(route('suppliers.import.confirm', ['token' => $token]));

        $this->assertSame(0, Supplier::count());
    }
}
