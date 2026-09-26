<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessModule;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\PosRegister;
use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PosModuleTest extends TestCase
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

    private function user(): User
    {
        return User::create([
            'name' => 'POS Owner',
            'email' => Str::random(10).'@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    private function business(User $user): Business
    {
        $business = Business::create(['name' => 'POS Business']);
        $roles = $business->provisionDefaultRoles();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['owner']);

        foreach (['dashboard', 'settings', 'products', 'inventory', 'accounting', 'pos'] as $module) {
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

    private function product(string $name = 'POS Product', string $sku = 'POS-001', string $price = '100.0000'): Product
    {
        return Product::create([
            'type' => 'product',
            'name' => $name,
            'sku' => $sku,
            'sale_price' => $price,
        ]);
    }

    private function setupRegister(User $user, Business $business): array
    {
        $this->actIn($user, $business);

        $warehouse = Warehouse::create([
            'code' => 'POS-WH',
            'name' => 'POS Warehouse',
            'is_active' => true,
        ]);

        $register = PosRegister::create([
            'warehouse_id' => $warehouse->id,
            'code' => 'REG-01',
            'name' => 'Main Register',
            'is_active' => true,
        ]);

        return [$warehouse, $register];
    }

    public function test_pos_schema_registry_permissions_and_numbering_exist(): void
    {
        foreach (['pos_registers', 'pos_shifts', 'pos_sales', 'pos_sale_items'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $this->assertArrayHasKey('pos', config('modules.registry'));
        $this->assertSame('POS', config('numbering.prefixes.pos_sale'));
        $this->assertContains('pos.view', config('permissions.groups.pos'));
        $this->assertContains('pos.sell', config('permissions.groups.pos'));
        $this->assertContains('pos.manage', config('permissions.groups.pos'));
    }

    public function test_cash_checkout_reduces_stock_posts_balanced_accounting_and_returns_receipt(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        [$warehouse, $register] = $this->setupRegister($user, $business);
        $product = $this->product();

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '10.0000',
            'unit_cost' => '60.0000',
            'occurred_at' => now(),
        ]);

        $this->post('/pos/registers/'.$register->id.'/open-shift', [
            'opening_cash' => '50.0000',
        ])->assertRedirect();

        $shift = PosShift::firstOrFail();

        $response = $this->post('/pos/shifts/'.$shift->id.'/checkout', [
            'items' => json_encode([['product_id' => $product->id, 'quantity' => 2]]),
            'payment_method' => 'cash',
            'discount_amount' => '10.0000',
            'amount_tendered' => '200.0000',
        ]);

        $sale = PosSale::with('items')->firstOrFail();

        $response->assertRedirect(route('pos.receipt', $sale));
        $this->assertSame('POS-000001', $sale->sale_number);
        $this->assertSame('190.0000', $sale->total);
        $this->assertSame('10.0000', $sale->change_due);
        $this->assertSame('60.0000', $sale->items->first()->unit_cost);
        $this->assertSame('120.0000', $sale->items->first()->cost_total);

        $this->assertSame(
            8.0,
            (float) StockMovement::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->sum('quantity'),
        );

        $entry = JournalEntry::with('lines')->where('source_type', PosSale::class)->firstOrFail();
        $debits = $entry->lines->sum(fn ($line) => (float) $line->debit);
        $credits = $entry->lines->sum(fn ($line) => (float) $line->credit);

        $this->assertEquals($debits, $credits);
        $this->assertEquals(310.0, $debits);

        $this->get('/pos/sales/'.$sale->id.'/receipt')
            ->assertOk()
            ->assertSee($sale->sale_number)
            ->assertSee('190.00');
    }

    public function test_checkout_refuses_insufficient_stock_without_partial_writes(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        [$warehouse, $register] = $this->setupRegister($user, $business);
        $product = $this->product();

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '1.0000',
            'unit_cost' => '60.0000',
            'occurred_at' => now(),
        ]);

        $this->post('/pos/registers/'.$register->id.'/open-shift', ['opening_cash' => 0]);
        $shift = PosShift::firstOrFail();

        $this->post('/pos/shifts/'.$shift->id.'/checkout', [
            'items' => json_encode([['product_id' => $product->id, 'quantity' => 2]]),
            'payment_method' => 'cash',
            'amount_tendered' => '500',
        ])->assertSessionHasErrors('cart');

        $this->assertDatabaseCount('pos_sales', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertSame(1.0, (float) StockMovement::sum('quantity'));
    }

    public function test_credit_sale_requires_customer_and_posts_to_receivable_when_selected(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        [$warehouse, $register] = $this->setupRegister($user, $business);
        $product = $this->product();

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '5.0000',
            'unit_cost' => '25.0000',
            'occurred_at' => now(),
        ]);

        $this->post('/pos/registers/'.$register->id.'/open-shift', ['opening_cash' => 0]);
        $shift = PosShift::firstOrFail();

        $this->post('/pos/shifts/'.$shift->id.'/checkout', [
            'items' => json_encode([['product_id' => $product->id, 'quantity' => 1]]),
            'payment_method' => 'credit',
        ])->assertSessionHasErrors('cart');

        $customer = Customer::create(['name' => 'Credit Customer']);

        $this->post('/pos/shifts/'.$shift->id.'/checkout', [
            'items' => json_encode([['product_id' => $product->id, 'quantity' => 1]]),
            'payment_method' => 'credit',
            'customer_id' => $customer->id,
        ])->assertRedirect();

        $sale = PosSale::firstOrFail();
        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertSame('credit', $sale->payment_method);
        $this->assertSame('0.0000', $sale->amount_tendered);

        $entry = JournalEntry::with('lines.account')->where('source_type', PosSale::class)->firstOrFail();
        $this->assertTrue($entry->lines->contains(
            fn ($line) => $line->account?->code === 'POS-AR' && (float) $line->debit === 100.0,
        ));
    }

    public function test_void_reverses_stock_and_accounting_and_shift_close_ignores_voided_cash_sale(): void
    {
        $user = $this->user();
        $business = $this->business($user);
        [$warehouse, $register] = $this->setupRegister($user, $business);
        $product = $this->product();

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '10.0000',
            'unit_cost' => '40.0000',
            'occurred_at' => now(),
        ]);

        $this->post('/pos/registers/'.$register->id.'/open-shift', ['opening_cash' => '25.0000']);
        $shift = PosShift::firstOrFail();

        $this->post('/pos/shifts/'.$shift->id.'/checkout', [
            'items' => json_encode([['product_id' => $product->id, 'quantity' => 2]]),
            'payment_method' => 'cash',
            'amount_tendered' => '200.0000',
        ]);

        $sale = PosSale::firstOrFail();

        $this->post('/pos/sales/'.$sale->id.'/void', [
            'reason' => 'Customer returned immediately',
        ])->assertRedirect();

        $sale->refresh();
        $this->assertSame('voided', $sale->status);
        $this->assertSame(10.0, (float) StockMovement::where('product_id', $product->id)->sum('quantity'));

        $this->assertDatabaseHas('journal_entries', [
            'number' => 'V-'.$sale->sale_number,
            'source_type' => 'pos_void',
            'source_id' => $sale->id,
        ]);

        $reversal = JournalEntry::with('lines')->where('number', 'V-'.$sale->sale_number)->firstOrFail();
        $this->assertEquals(
            $reversal->lines->sum(fn ($line) => (float) $line->debit),
            $reversal->lines->sum(fn ($line) => (float) $line->credit),
        );

        $this->post('/pos/shifts/'.$shift->id.'/close', [
            'closing_cash' => '25.0000',
        ])->assertRedirect();

        $shift->refresh();
        $this->assertSame('closed', $shift->status);
        $this->assertSame('25.0000', $shift->expected_cash);
        $this->assertSame('0.0000', $shift->cash_variance);
    }

    public function test_only_cashier_who_opened_shift_can_checkout(): void
    {
        $owner = $this->user();
        $business = $this->business($owner);
        [$warehouse, $register] = $this->setupRegister($owner, $business);
        $product = $this->product();

        StockMovement::create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'type' => 'opening',
            'quantity' => '5.0000',
            'unit_cost' => '20.0000',
            'occurred_at' => now(),
        ]);

        $this->post('/pos/registers/'.$register->id.'/open-shift', ['opening_cash' => 0]);
        $shift = PosShift::firstOrFail();

        $other = $this->user();
        $roles = $business->provisionDefaultRoles();
        $membership = $other->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles['admin']);
        $this->actIn($other, $business);

        $this->post('/pos/shifts/'.$shift->id.'/checkout', [
            'items' => json_encode([['product_id' => $product->id, 'quantity' => 1]]),
            'payment_method' => 'cash',
            'amount_tendered' => '100',
        ])->assertSessionHasErrors('cart');

        $this->assertDatabaseCount('pos_sales', 0);
    }
}
