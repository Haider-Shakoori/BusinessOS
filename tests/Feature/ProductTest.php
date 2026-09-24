<?php

namespace Tests\Feature;

use App\Enums\ProductType;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Category;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use App\Services\BusinessSettings;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Product / service registry CRUD + tenancy + optional-tax behaviour (Batch 12).
 *
 * Products and services share ONE table; every product route lives under the
 * `products` module and reads/writes are split by products.view / products.manage.
 * Category/unit/tax references are tenant-safe (scoped exists + active-only
 * dropdowns). The optional-tax feature only influences the UI and validation:
 * enabling/disabling never touches the stored tax_id.
 */
class ProductTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        // Fresh in-memory connection, never migrate:fresh or a developer database.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
    }

    private function makeUser(string $name = 'Product User'): User
    {
        return User::create(['name' => $name, 'email' => Str::random(10).'@example.test', 'password' => Hash::make('password')]);
    }

    private function makeBusiness(string $name): Business
    {
        return Business::create(['name' => $name]);
    }

    /**
     * @return array{business: Business, membership: BusinessMembership, roles: array<string, Role>}
     */
    private function provision(User $user, string $businessName, string $roleSlug): array
    {
        $business = $this->makeBusiness($businessName);
        $roles = $business->provisionDefaultRoles();
        $business->provisionDefaultModules();
        $membership = $user->memberships()->create(['business_id' => $business->id]);
        $membership->assignRole($roles[$roleSlug]);

        return [$business, $membership, $roles];
    }

    private function enableProducts(Business $business): void
    {
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => 'products'],
            ['enabled' => true],
        );
    }

    private function makeCategory(Business $business, array $attributes = []): Category
    {
        $category = new Category(array_merge(['name' => 'Category '.Str::random(5)], $attributes));
        $category->business_id = $business->id;
        $category->save();

        return $category;
    }

    private function makeUnit(Business $business, array $attributes = []): Unit
    {
        $unit = new Unit(array_merge(['name' => 'Unit '.Str::random(5)], $attributes));
        $unit->business_id = $business->id;
        $unit->save();

        return $unit;
    }

    private function makeTax(Business $business, array $attributes = []): Tax
    {
        $tax = new Tax(array_merge(['name' => 'Tax '.Str::random(5), 'rate' => '10.0000'], $attributes));
        $tax->business_id = $business->id;
        $tax->save();

        return $tax;
    }

    private function makeProduct(Business $business, array $attributes = []): Product
    {
        $product = new Product(array_merge([
            'type' => ProductType::Product,
            'name' => 'Product '.Str::random(5),
            'sale_price' => '10.0000',
        ], $attributes));
        $product->business_id = $business->id;
        $product->save();

        return $product;
    }

    private function sessionKey(): string
    {
        return config('business.context.session_key');
    }

    private function rebuildContext(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function actIn(User $user, Business $business): void
    {
        $this->flushSession();
        $this->actingAs($user);
        session([$this->sessionKey() => $business->id]);
        $this->rebuildContext();
    }

    /**
     * Enable the tax feature via the BusinessSettings service — the same
     * authoritative source the tax-aware UI/validation checks. Call AFTER
     * actIn() so the current business resolves; with no business context the
     * write is a no-op by design.
     */
    private function enableTax(): void
    {
        $this->rebuildContext();
        app(BusinessSettings::class)->set('general.tax_enabled', '1');
    }

    private function disableTax(): void
    {
        $this->rebuildContext();
        app(BusinessSettings::class)->set('general.tax_enabled', '0');
    }

    // --- Schema --------------------------------------------------------------

    public function test_product_schema_is_in_place(): void
    {
        $this->assertTrue(Schema::hasTable('products'));

        foreach (['business_id', 'type', 'name', 'sku', 'description', 'category_id', 'unit_id', 'tax_id', 'sale_price', 'created_at', 'updated_at', 'deleted_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('products', $column), "Expected products.$column to exist.");
        }

        // sale_price is DECIMAL(16,4) — never FLOAT/DOUBLE. SQLite exposes a
        // NUMERIC affinity (SQLiteGrammar::typeDecimal), the compatible form of
        // DECIMAL; the authoritative check is the migration's declared type +
        // the runtime type.
        $migration = file_get_contents(base_path('database/migrations/2026_09_11_000013_create_products_table.php'));
        $this->assertStringContainsString("decimal('sale_price', 16, 4)", $migration);

        $column = collect(Schema::getColumns('products'))->firstWhere('name', 'sale_price');
        $this->assertNotNull($column, 'products.sale_price column should be discoverable.');
        $type = strtolower((string) $column['type']);
        $this->assertNotContains($type, ['float', 'double', 'real'], "sale_price must never be a floating point type, got '$type'.");
        $this->assertSame('numeric', $type);
    }

    // --- ProductType enum ----------------------------------------------------

    public function test_products_only_accept_product_or_service_type(): void
    {
        $this->assertSame('product', ProductType::Product->value);
        $this->assertSame('service', ProductType::Service->value);

        $user = $this->makeUser('Type Owner');
        [$business] = $this->provision($user, 'Type Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        // Unsupported type value is rejected at validation.
        $this->post('/products', [
            'type' => 'gadget',
            'name' => 'Gadget',
            'sale_price' => '10.0000',
        ])->assertSessionHasErrors('type');

        // Both supported kinds are persisted in the SAME table.
        $this->post('/products', [
            'type' => 'product',
            'name' => 'Laptop',
            'sale_price' => '1234.5678',
        ])->assertRedirect(route('products.index'));
        $this->post('/products', [
            'type' => 'service',
            'name' => 'Setup Service',
            'sale_price' => '99.0000',
        ])->assertRedirect(route('products.index'));

        $this->assertDatabaseCount('products', 2);
        $this->assertSame('product', Product::where('name', 'Laptop')->firstOrFail()->type->value);
        $this->assertSame('service', Product::where('name', 'Setup Service')->firstOrFail()->type->value);
        $this->assertSame($business->id, Product::where('name', 'Laptop')->firstOrFail()->business_id);
    }

    // --- DECIMAL(16,4) precision persistence ---------------------------------

    public function test_sale_price_is_stored_as_exact_decimal(): void
    {
        $user = $this->makeUser('Price Owner');
        [$business] = $this->provision($user, 'Price Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->post('/products', [
            'type' => 'product',
            'name' => 'Gauge',
            'sale_price' => '1234.5678',
        ])->assertRedirect(route('products.index'));

        $product = Product::where('name', 'Gauge')->firstOrFail();
        $this->assertSame('1234.5678', $product->sale_price);
        $this->assertSame('1234.5678', (string) $product->sale_price);

        $product->update(['sale_price' => '0.0001']);
        $this->assertSame('0.0001', $product->fresh()->sale_price);
    }

    // --- Route guarding: auth + business -------------------------------------

    public function test_product_routes_require_authentication_and_a_business(): void
    {
        $this->get('/products')->assertRedirect(route('login'));
        $this->post('/products', ['name' => 'Nope', 'sale_price' => '10'])->assertRedirect(route('login'));

        $user = $this->makeUser();
        $this->actingAs($user)->get('/products')->assertRedirect(route('business.create'));
    }

    // --- Module boundary -----------------------------------------------------

    public function test_product_routes_require_the_products_module(): void
    {
        $user = $this->makeUser('Mod Owner');
        [$business] = $this->provision($user, 'Mod Prod Co.', 'owner');

        $this->actIn($user, $business);
        $this->assertTrue(Gate::allows('products.manage'));

        // Module disabled: 403 even with permission held.
        $this->get('/products')->assertForbidden();
        $this->post('/products', ['name' => 'Nope', 'sale_price' => '10'])->assertForbidden();

        // Module enabled: 200.
        $this->enableProducts($business);
        $this->get('/products')->assertOk();
    }

    // --- Permission boundaries ------------------------------------------------

    public function test_reads_require_products_view_and_writes_require_products_manage(): void
    {
        $user = $this->makeUser('No Prod Perm');
        [$business] = $this->provision($user, 'No Perm Prod Co.', 'viewer');
        $this->enableProducts($business);

        $this->actIn($user, $business);

        $this->assertFalse(Gate::allows('products.view'));
        $this->get('/products')->assertForbidden();
        $this->get(route('products.create'))->assertForbidden();
    }

    public function test_member_with_view_only_can_read_but_cannot_modify(): void
    {
        $user = $this->makeUser('Prod Viewer');
        [$business, , $roles] = $this->provision($user, 'Read Prod Co.', 'viewer');
        $this->enableProducts($business);

        $viewId = Permission::where('name', 'products.view')->value('id');
        $roles['viewer']->permissions()->syncWithoutDetaching([$viewId]);
        $this->rebuildContext();

        $this->actIn($user, $business);
        $this->assertTrue(Gate::allows('products.view'));
        $this->assertFalse(Gate::allows('products.manage'));

        $this->makeProduct($business, ['name' => 'Readable Product', 'sale_price' => '25.0000']);

        $this->get('/products')
            ->assertOk()
            ->assertSee('Readable Product')
            ->assertSee(__('products.view_only'));

        // Every write path is denied before validation.
        $this->get(route('products.create'))->assertForbidden();
        $this->post('/products', ['type' => 'product', 'name' => 'Nope', 'sale_price' => '10.0000'])->assertForbidden();
        $this->get(route('products.edit', Product::where('name', 'Readable Product')->firstOrFail()))->assertForbidden();
        $this->delete(route('products.destroy', Product::where('name', 'Readable Product')->firstOrFail()))->assertForbidden();

        $this->assertDatabaseCount('products', 1);
    }

    // --- Owner/manager happy path --------------------------------------------

    public function test_owner_can_create_update_and_soft_delete_a_product(): void
    {
        $user = $this->makeUser('Prod Owner');
        [$business] = $this->provision($user, 'Own Prod Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $category = $this->makeCategory($business, ['name' => 'Electronics']);
        $unit = $this->makeUnit($business, ['name' => 'Piece', 'short_name' => 'pcs']);

        $this->post('/products', [
            'type' => 'product',
            'name' => 'Keyboard',
            'sku' => 'KB-001',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sale_price' => '49.9900',
        ])->assertRedirect(route('products.index'))->assertSessionHas('status', __('products.created'));

        $product = Product::where('name', 'Keyboard')->firstOrFail();
        $this->assertSame($business->id, $product->business_id);
        $this->assertSame('product', $product->type->value);
        $this->assertSame('KB-001', $product->sku);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame($unit->id, $product->unit_id);
        $this->assertNull($product->tax_id);

        $this->patch(route('products.update', $product), [
            'type' => 'product',
            'name' => 'Mechanical Keyboard',
            'sku' => 'KB-001',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'sale_price' => '59.9900',
        ])->assertRedirect(route('products.index'))->assertSessionHas('status', __('products.updated'));

        $this->assertSame('Mechanical Keyboard', $product->fresh()->name);
        $this->assertSame('59.9900', $product->fresh()->sale_price);

        $this->delete(route('products.destroy', $product))
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('status', __('products.deleted'));

        $this->assertSoftDeleted('products', ['id' => $product->id]);
        $this->get('/products')->assertOk()->assertDontSee('Mechanical Keyboard');
    }

    public function test_admin_role_can_manage_products(): void
    {
        $user = $this->makeUser('Prod Admin');
        [$business] = $this->provision($user, 'Admin Prod Co.', 'admin');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->assertTrue(Gate::allows('products.manage'));
        $this->post('/products', ['type' => 'product', 'name' => 'Managed Product', 'sale_price' => '10.0000'])
            ->assertRedirect();
        $this->assertDatabaseHas('products', ['business_id' => $business->id, 'name' => 'Managed Product', 'sale_price' => '10.0000']);
    }

    // --- Tenancy: forged identifiers are ignored -----------------------------

    public function test_create_ignores_forged_business_id(): void
    {
        $user = $this->makeUser('Prod Forger');
        [$business] = $this->provision($user, 'Forge Prod Co.', 'owner');
        $this->enableProducts($business);
        $other = $this->makeBusiness('Victim Prod Co.');

        $this->actIn($user, $business);

        $this->post('/products', [
            'type' => 'product',
            'name' => 'Legit Product',
            'sale_price' => '10.0000',
            'business_id' => $other->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('products', ['name' => 'Legit Product', 'business_id' => $business->id]);
        $this->assertDatabaseMissing('products', ['business_id' => $other->id]);
    }

    public function test_update_ignores_forged_business_id(): void
    {
        $user = $this->makeUser('Up Forger');
        [$business] = $this->provision($user, 'Up Forge Prod Co.', 'owner');
        $this->enableProducts($business);
        $other = $this->makeBusiness('Other Victim Prod Co.');
        $product = $this->makeProduct($business, ['name' => 'Stable Product']);

        $this->actIn($user, $business);

        $this->patch(route('products.update', $product), [
            'type' => 'product',
            'name' => 'Stable Product',
            'sale_price' => '10.0000',
            'business_id' => $other->id,
        ])->assertRedirect(route('products.index'));

        $this->assertSame($business->id, $product->fresh()->business_id);
        $this->assertDatabaseMissing('products', ['business_id' => $other->id]);
    }

    // --- Isolation: index + search are tenant-scoped -------------------------

    public function test_index_and_search_show_only_current_business_products(): void
    {
        $user = $this->makeUser('Prod Multi');
        [$businessA] = $this->provision($user, 'Alpha Prod Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Prod Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $this->actIn($user, $businessA);
        $this->makeProduct($businessA, ['name' => 'Alpha Gadget', 'sale_price' => '10.0000']);
        $this->makeProduct($businessA, ['name' => 'Alpha Widget', 'sale_price' => '20.0000']);
        $this->makeProduct($businessB, ['name' => 'Beta Widget Co', 'sale_price' => '30.0000']);

        $this->get('/products')
            ->assertOk()
            ->assertSee('Alpha Gadget')
            ->assertSee('Alpha Widget')
            ->assertDontSee('Beta Widget Co');

        $this->get('/products?search=Gadget')
            ->assertOk()
            ->assertSee('Alpha Gadget')
            ->assertDontSee('Alpha Widget');
    }

    public function test_search_matches_name_and_sku_only_within_the_business(): void
    {
        $user = $this->makeUser('Prod Sku Search');
        [$businessA] = $this->provision($user, 'Alpha SKU Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta SKU Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $this->actIn($user, $businessA);
        $this->makeProduct($businessA, ['name' => 'Thing One', 'sku' => 'ABC-001', 'sale_price' => '5.0000']);
        $this->makeProduct($businessB, ['name' => 'Thing One', 'sku' => 'ABC-001', 'sale_price' => '6.0000']);

        $this->get('/products?search=ABC-001')
            ->assertOk()
            ->assertSee('Thing One');
        $this->assertDatabaseHas('products', ['business_id' => $businessA->id, 'name' => 'Thing One', 'sku' => 'ABC-001']);
    }

    public function test_cross_business_edit_update_and_delete_are_blocked(): void
    {
        $user = $this->makeUser('Prod Cross');
        [$businessA] = $this->provision($user, 'Alpha Cross Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Cross Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $this->actIn($user, $businessA);

        $foreign = $this->makeProduct($businessB, ['name' => 'Foreign Product']);

        $this->get(route('products.edit', $foreign))->assertNotFound();
        $this->patch(route('products.update', $foreign), ['type' => 'product', 'name' => 'Hijacked', 'sale_price' => '10.0000'])->assertNotFound();
        $this->delete(route('products.destroy', $foreign))->assertNotFound();

        $this->assertSame('Foreign Product', $foreign->fresh()->name);
        $this->assertFalse($foreign->fresh()->trashed());
    }

    // --- Type filtering -------------------------------------------------------

    public function test_index_filters_by_product_type(): void
    {
        $user = $this->makeUser('Type Filter');
        [$business] = $this->provision($user, 'Filter Prod Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->makeProduct($business, ['type' => ProductType::Product, 'name' => 'Hard Item', 'sale_price' => '1.0000']);
        $this->makeProduct($business, ['type' => ProductType::Service, 'name' => 'Soft Service', 'sale_price' => '2.0000']);

        $this->get('/products?type=product')
            ->assertOk()
            ->assertSee('Hard Item')
            ->assertDontSee('Soft Service');

        $this->get('/products?type=service')
            ->assertOk()
            ->assertSee('Soft Service')
            ->assertDontSee('Hard Item');

        // Invalid type value degrades to "all" rather than erroring.
        $this->get('/products?type=banana')
            ->assertOk()
            ->assertSee('Hard Item')
            ->assertSee('Soft Service');
    }

    // --- Multi-business switch: mandatory scenario ---------------------------

    public function test_mandatory_multi_business_switch_isolation_with_master_data(): void
    {
        $user = $this->makeUser('Prod Switch');
        [$businessA] = $this->provision($user, 'Alpha Prod Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Prod Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        // Business A: tax ENABLED, own master data + product A.
        $this->actIn($user, $businessA);
        $this->enableTax();
        $categoryA = $this->makeCategory($businessA, ['name' => 'Cat A']);
        $unitA = $this->makeUnit($businessA, ['name' => 'Unit A']);
        $taxA = $this->makeTax($businessA, ['name' => 'Tax A', 'rate' => '5.0000']);
        $productA = $this->makeProduct($businessA, ['type' => ProductType::Product, 'name' => 'Product A', 'category_id' => $categoryA->id, 'unit_id' => $unitA->id, 'tax_id' => $taxA->id, 'sale_price' => '100.0000']);

        // Business B: tax DISABLED, own master data + service B.
        $this->actIn($user, $businessB);
        $categoryB = $this->makeCategory($businessB, ['name' => 'Cat B']);
        $unitB = $this->makeUnit($businessB, ['name' => 'Unit B']);
        $taxB = $this->makeTax($businessB, ['name' => 'Tax B', 'rate' => '10.0000']);
        $serviceB = $this->makeProduct($businessB, ['type' => ProductType::Service, 'name' => 'Service B', 'category_id' => $categoryB->id, 'unit_id' => $unitB->id, 'sale_price' => '200.0000']);

        // --- CURRENT BUSINESS A ---
        $this->actIn($user, $businessA);
        $this->get('/products')
            ->assertOk()
            ->assertSee('Product A')
            ->assertDontSee('Service B');

        // Dropdowns expose A's master data and NOT B's.
        $create = $this->get(route('products.create'))->assertOk();
        $create->assertSee('Cat A')->assertDontSee('Cat B');
        $create->assertSee('Unit A')->assertDontSee('Unit B');
        $create->assertSee('name="tax_id"', false);
        $create->assertSee('Tax A')->assertDontSee('Tax B');

        // Cross-business master data is rejected by tenant-safe validation.
        $this->post('/products', [
            'type' => 'product',
            'name' => 'Bad Reference',
            'sale_price' => '10.0000',
            'category_id' => $categoryB->id,
            'unit_id' => $unitB->id,
            'tax_id' => $taxB->id,
        ])->assertSessionHasErrors(['category_id', 'unit_id', 'tax_id']);

        // --- SWITCH TO BUSINESS B ---
        $this->post(route('business.switch'), ['business_id' => $businessB->id])->assertRedirect(route('app.home'));

        $this->get('/products')
            ->assertOk()
            ->assertSee('Service B')
            ->assertDontSee('Product A');

        // Product A's direct URL is inaccessible from B.
        $this->get(route('products.edit', $productA))->assertNotFound();

        // B's master data only; tax selector hidden because tax is disabled.
        $createB = $this->get(route('products.create'))->assertOk();
        $createB->assertSee('Cat B')->assertDontSee('Cat A');
        $createB->assertSee('Unit B')->assertDontSee('Unit A');
        $createB->assertDontSee('name="tax_id"', false);

        // Cross-business reference attempts still fail from B -> A.
        $this->post('/products', [
            'type' => 'service',
            'name' => 'Bad Ref B',
            'sale_price' => '10.0000',
            'category_id' => $categoryA->id,
            'unit_id' => $unitA->id,
            'tax_id' => $taxA->id,
        ])->assertSessionHasErrors(['category_id', 'unit_id']);

        // B service is intact.
        $this->get('/products')->assertSee('Service B');
    }

    // --- SKU uniqueness (business-scoped, soft-delete aware) -----------------

    public function test_sku_is_unique_within_business_but_allowed_across_businesses(): void
    {
        $user = $this->makeUser('Sku Unique');
        [$businessA] = $this->provision($user, 'Alpha Sku Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Sku Co.', 'owner');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $this->actIn($user, $businessA);
        $this->makeProduct($businessA, ['name' => 'First', 'sku' => 'ABC-001', 'sale_price' => '5.0000']);

        // Same SKU again in the same business is rejected.
        $this->post('/products', ['type' => 'product', 'name' => 'Second', 'sku' => 'ABC-001', 'sale_price' => '6.0000'])
            ->assertSessionHasErrors('sku');

        // Editing keeps the same SKU (ignore self).
        $this->patch(route('products.update', Product::where('sku', 'ABC-001')->where('business_id', $businessA->id)->firstOrFail()), [
            'type' => 'product',
            'name' => 'First Renamed',
            'sku' => 'ABC-001',
            'sale_price' => '5.0000',
        ])->assertRedirect(route('products.index'));

        // Same SKU in another business is allowed.
        $this->actIn($user, $businessB);
        $this->post('/products', ['type' => 'product', 'name' => 'Third', 'sku' => 'ABC-001', 'sale_price' => '7.0000'])->assertRedirect();
        $this->assertDatabaseHas('products', ['business_id' => $businessB->id, 'name' => 'Third', 'sku' => 'ABC-001']);
    }

    public function test_soft_deleted_product_does_not_block_reusing_the_same_sku(): void
    {
        $user = $this->makeUser('Sku Recycle');
        [$business] = $this->provision($user, 'Recycle Sku Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $product = $this->makeProduct($business, ['name' => 'Old', 'sku' => 'OLD-1', 'sale_price' => '1.0000']);
        $this->delete(route('products.destroy', $product))->assertRedirect(route('products.index'));
        $this->assertSoftDeleted('products', ['id' => $product->id]);

        $this->post('/products', ['type' => 'product', 'name' => 'New', 'sku' => 'OLD-1', 'sale_price' => '2.0000'])->assertRedirect();
        $this->assertDatabaseCount('products', 2);
        $this->assertDatabaseHas('products', ['business_id' => $business->id, 'name' => 'New', 'sku' => 'OLD-1', 'deleted_at' => null]);
    }

    public function test_sku_is_optional(): void
    {
        $user = $this->makeUser('Sku Optional');
        [$business] = $this->provision($user, 'No Sku Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->post('/products', ['type' => 'product', 'name' => 'No SKU Product', 'sale_price' => '10.0000'])->assertRedirect();
        $this->assertNull(Product::where('name', 'No SKU Product')->firstOrFail()->sku);
    }

    // --- Category / Unit / Tax tenancy validation ----------------------------

    public function test_category_reference_must_belong_to_current_business(): void
    {
        $user = $this->makeUser('Cat Forge');
        [$businessA] = $this->provision($user, 'Alpha Cat Prod Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Cat Prod Co.', 'owner');
        $this->enableProducts($businessA);

        $this->actIn($user, $businessA);
        $foreignCategory = $this->makeCategory($businessB, ['name' => 'Foreign Category']);

        $this->post('/products', [
            'type' => 'product',
            'name' => 'Forge Cat Product',
            'sale_price' => '10.0000',
            'category_id' => $foreignCategory->id,
        ])->assertSessionHasErrors('category_id');
    }

    public function test_unit_reference_must_belong_to_current_business(): void
    {
        $user = $this->makeUser('Unit Forge');
        [$businessA] = $this->provision($user, 'Alpha Unit Prod Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Unit Prod Co.', 'owner');
        $this->enableProducts($businessA);

        $this->actIn($user, $businessA);
        $foreignUnit = $this->makeUnit($businessB, ['name' => 'Foreign Unit']);

        $this->post('/products', [
            'type' => 'product',
            'name' => 'Forge Unit Product',
            'sale_price' => '10.0000',
            'unit_id' => $foreignUnit->id,
        ])->assertSessionHasErrors('unit_id');
    }

    public function test_tax_reference_must_belong_to_current_business(): void
    {
        $user = $this->makeUser('Tax Forge');
        [$businessA] = $this->provision($user, 'Alpha Tax Prod Co.', 'owner');
        [$businessB] = $this->provision($user, 'Beta Tax Prod Co.', 'owner');
        $this->enableProducts($businessA);

        $this->actIn($user, $businessA);
        $this->enableTax();
        $foreignTax = $this->makeTax($businessB, ['name' => 'Foreign Tax', 'rate' => '5.0000']);

        $this->post('/products', [
            'type' => 'product',
            'name' => 'Forge Tax Product',
            'sale_price' => '10.0000',
            'tax_id' => $foreignTax->id,
        ])->assertSessionHasErrors('tax_id');
    }

    public function test_soft_deleted_master_data_is_not_selectable(): void
    {
        $user = $this->makeUser('Soft Master');
        [$business] = $this->provision($user, 'Soft Master Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);
        $this->enableTax();

        $category = $this->makeCategory($business, ['name' => 'Gone Category']);
        $unit = $this->makeUnit($business, ['name' => 'Gone Unit']);
        $tax = $this->makeTax($business, ['name' => 'Gone Tax', 'rate' => '5.0000']);

        $category->delete();
        $unit->delete();
        $tax->delete();

        // Soft-deleted master rows never appear as form options.
        $create = $this->get(route('products.create'))->assertOk();
        $create->assertDontSee('Gone Category');
        $create->assertDontSee('Gone Unit');
        $create->assertDontSee('Gone Tax');

        // And they are rejected by tenant-safe validation.
        $this->post('/products', [
            'type' => 'product',
            'name' => 'Bad Master Product',
            'sale_price' => '10.0000',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'tax_id' => $tax->id,
        ])->assertSessionHasErrors(['category_id', 'unit_id', 'tax_id']);
    }

    public function test_product_referencing_soft_deleted_master_data_stays_intact(): void
    {
        $user = $this->makeUser('Intact Master');
        [$business] = $this->provision($user, 'Intact Master Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $category = $this->makeCategory($business, ['name' => 'Doomed Category']);
        $product = $this->makeProduct($business, ['name' => 'Survivor', 'category_id' => $category->id, 'sale_price' => '5.0000']);

        $category->delete();

        // The product survives the master soft delete and renders gracefully.
        $product->refresh();
        $this->assertNull($product->category);
        $this->get('/products')->assertOk()->assertSee('Survivor');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
    }

    // --- Optional tax: UI + selector behaviour -------------------------------

    public function test_tax_selector_is_shown_when_enabled_and_hidden_when_disabled(): void
    {
        $user = $this->makeUser('Tax UI');
        [$business] = $this->provision($user, 'Tax UI Co.', 'owner');
        $this->enableProducts($business);

        $this->actIn($user, $business);
        $this->enableTax();

        // Enabled: create page exposes the tax selector.
        $this->get(route('products.create'))->assertOk()->assertSee('name="tax_id"', false);

        $this->disableTax();
        // Disabled: selector hidden.
        $this->get(route('products.create'))->assertOk()->assertDontSee('name="tax_id"', false);
    }

    public function test_tax_is_validated_when_enabled(): void
    {
        $user = $this->makeUser('Tax Valid');
        [$business] = $this->provision($user, 'Valid Tax Prod Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);
        $this->enableTax();

        // No tax_id offered is fine (nullable).
        $this->post('/products', ['type' => 'product', 'name' => 'No Tax Product', 'sale_price' => '10.0000'])->assertRedirect();
        $this->assertNull(Product::where('name', 'No Tax Product')->firstOrFail()->tax_id);

        // A bogus tax_id is rejected while enabled.
        $this->post('/products', ['type' => 'product', 'name' => 'Bogus Tax Product', 'sale_price' => '10.0000', 'tax_id' => 999999])
            ->assertSessionHasErrors('tax_id');
    }

    // --- Mandatory tax preservation scenario ---------------------------------

    public function test_disabling_tax_preserves_the_stored_tax_id_on_unrelated_updates(): void
    {
        $user = $this->makeUser('Tax Preserve');
        [$business] = $this->provision($user, 'Preserve Prod Co.', 'owner');
        $this->enableProducts($business);

        $this->actIn($user, $business);
        $this->enableTax();
        $vat = $this->makeTax($business, ['name' => 'VAT 5', 'rate' => '5.0000']);

        $this->post('/products', [
            'type' => 'product',
            'name' => 'Product A',
            'sale_price' => '100.0000',
            'tax_id' => $vat->id,
        ])->assertRedirect();

        $product = Product::where('name', 'Product A')->firstOrFail();
        $this->assertSame($vat->id, $product->tax_id);

        // Business disables tax. User edits ONLY the name; no tax selector is
        // submitted (the UI is hidden). update() must not clear tax_id.
        $this->disableTax();
        $this->patch(route('products.update', $product), [
            'type' => 'product',
            'name' => 'Updated Product A',
            'sale_price' => '100.0000',
        ])->assertRedirect(route('products.index'));

        $product->refresh();
        $this->assertSame('Updated Product A', $product->name);
        $this->assertSame($vat->id, $product->tax_id);

        // A forged tax_id while the feature is OFF can neither change nor clear
        // the assignment.
        $otherTax = $this->makeTax($business, ['name' => 'Stealth Tax', 'rate' => '9.0000']);
        $this->patch(route('products.update', $product), [
            'type' => 'product',
            'name' => 'Still Updated',
            'sale_price' => '100.0000',
            'tax_id' => $otherTax->id,
        ])->assertRedirect(route('products.index'));

        $product->refresh();
        $this->assertSame('Still Updated', $product->name);
        $this->assertNotEquals($otherTax->id, $product->tax_id);
        $this->assertSame($vat->id, $product->tax_id);

        // Re-enabling tax: the existing VAT relationship becomes visible again
        // and re-usable — the selector returns with VAT selected.
        $this->enableTax();
        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('name="tax_id"', false)
            ->assertSee('VAT 5');
        $this->assertSame($vat->id, $product->tax()->firstOrFail()->id);
    }

    // --- Validation ----------------------------------------------------------

    public function test_product_validation_rejects_invalid_payloads(): void
    {
        $user = $this->makeUser('Prod Valid');
        [$business] = $this->provision($user, 'Valid Prod Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        // Missing type.
        $this->post('/products', ['name' => 'No Type', 'sale_price' => '10'])
            ->assertSessionHasErrors('type');
        // Missing name.
        $this->post('/products', ['type' => 'product', 'name' => '', 'sale_price' => '10'])
            ->assertSessionHasErrors('name');
        // Name too long.
        $this->post('/products', ['type' => 'product', 'name' => str_repeat('a', 101), 'sale_price' => '10'])
            ->assertSessionHasErrors('name');
        // Missing price.
        $this->post('/products', ['type' => 'product', 'name' => 'No Price'])
            ->assertSessionHasErrors('sale_price');
        // Negative price.
        $this->post('/products', ['type' => 'product', 'name' => 'Negative', 'sale_price' => '-1'])
            ->assertSessionHasErrors('sale_price');
        // Too many decimal places.
        $this->post('/products', ['type' => 'product', 'name' => 'Too Precise', 'sale_price' => '10.12345'])
            ->assertSessionHasErrors('sale_price');
        // Non-numeric price.
        $this->post('/products', ['type' => 'product', 'name' => 'Not A Number', 'sale_price' => 'abc'])
            ->assertSessionHasErrors('sale_price');
        // SKU too long.
        $this->post('/products', ['type' => 'product', 'name' => 'Long SKU', 'sale_price' => '10', 'sku' => str_repeat('x', 51)])
            ->assertSessionHasErrors('sku');

        $this->assertDatabaseCount('products', 0);
    }

    // --- Pagination ----------------------------------------------------------

    public function test_pagination_preserves_search_and_type(): void
    {
        $user = $this->makeUser('Prod Pager');
        [$business] = $this->provision($user, 'Page Prod Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        for ($i = 1; $i <= 20; $i++) {
            $this->makeProduct($business, ['type' => ProductType::Product, 'name' => sprintf('Alpha Prod %02d', $i), 'sale_price' => '1.0000']);
        }
        $this->makeProduct($business, ['type' => ProductType::Service, 'name' => 'Alpha Service', 'sale_price' => '2.0000']);

        $this->get('/products?search=Alpha&type=product')
            ->assertOk()
            ->assertSee('Alpha Prod 01')
            ->assertDontSee('Alpha Service')
            ->assertSee('search=Alpha', false)
            ->assertSee('type=product', false);

        $this->get('/products?search=Alpha&type=product&page=2')
            ->assertOk()
            ->assertSee('Alpha Prod 16');
    }

    // --- Navigation reaches the real products route --------------------------

    public function test_products_navigation_reaches_the_real_route(): void
    {
        $navigation = config('modules.registry.products.navigation');
        $this->assertSame('products.index', $navigation['route']);

        $user = $this->makeUser('Nav Owner');
        [$business] = $this->provision($user, 'Nav Prod Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        // The parent Products entry renders (module + permission present)...
        $this->get('/app')
            ->assertOk()
            ->assertSee(__('modules.products'))
            ->assertSee(route('products.index'), false);

        // ...and the catalog children remain coherent alongside it.
        $config = config('modules.registry.products.navigation.children');
        $this->assertSame(['categories.index', 'units.index', 'taxes.index'], array_column($config, 'route'));
    }

    public function test_products_index_renders_the_real_route_href(): void
    {
        $user = $this->makeUser('Href Owner');
        [$business] = $this->provision($user, 'Href Prod Co.', 'owner');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->get('/products')
            ->assertOk()
            ->assertSee(route('products.index'), false);
    }
}
