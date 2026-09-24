<?php

namespace Tests\Feature;

use App\Jobs\ProcessImportJob;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\User;
use App\Services\BusinessSettings;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Product / service CSV import (Batch 20).
 *
 * Product imports additionally resolve human-readable category, unit and tax
 * names into their database IDs — only for the CURRENT business — and they
 * gate the tax column on the business-level tax feature toggle.
 */
class ProductImportTest extends TestCase
{
    private const HEADER = 'name,type,sku,sale_price,description,category,unit,tax';

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->seed(PermissionSeeder::class);
        Storage::fake('local');
    }

    private function makeUser(string $name = 'Import User'): User
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
    private function provision(User $user, string $businessName, string $roleSlug = 'admin'): array
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

    private function enableTax(): void
    {
        $this->rebuildContext();
        app(BusinessSettings::class)->set('general.tax_enabled', '1');
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

    private function switchTo(Business $business): void
    {
        session([$this->sessionKey() => $business->id]);
        $this->rebuildContext();
    }

    // / --- helpers -----------------------------------------------------------------

    private function csv(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function tokenFrom(string $location): string
    {
        $path = parse_url($location, PHP_URL_PATH);

        return (string) basename((string) $path);
    }

    private function validCsv(string ...$rows): string
    {
        return self::HEADER."\n".implode("\n", $rows)."\n";
    }

    private function makeCategory(Business $business, string $name = 'Electronics'): Category
    {
        $category = new Category(['name' => $name]);
        $category->business_id = $business->id;
        $category->save();

        return $category;
    }

    private function makeUnit(Business $business, string $name = 'Piece'): Unit
    {
        $unit = new Unit(['name' => $name]);
        $unit->business_id = $business->id;
        $unit->save();

        return $unit;
    }

    private function makeTax(Business $business, string $name = 'VAT'): Tax
    {
        $tax = new Tax(['name' => $name, 'rate' => '15.0000']);
        $tax->business_id = $business->id;
        $tax->save();

        return $tax;
    }

    private function makeExistingProduct(Business $business, string $sku = 'EXISTS'): Product
    {
        $product = new Product(['type' => 'product', 'name' => 'Existing', 'sale_price' => '10.0000', 'sku' => $sku]);
        $product->business_id = $business->id;
        $product->save();

        return $product;
    }

    // --- guards -------------------------------------------------------------------

    public function test_viewer_without_manage_permission_is_forbidden_everywhere(): void
    {
        $user = $this->makeUser('Viewer Prod');
        [$business] = $this->provision($user, 'Viewer Prod Co.', 'viewer');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->get('/products/import')->assertForbidden();
        $this->get('/products/import/template')->assertForbidden();
        $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Widget,product,,10,,,,')),
        ])->assertForbidden();
    }

    public function test_products_module_must_be_enabled(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'No Prod Mod Co.');
        $this->actIn($user, $business);

        $this->get('/products/import')->assertForbidden();
    }

    // --- header validation -------------------------------------------------------

    public function test_unknown_header_column_blocks_preview(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Bad Header Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $location = $this->post('/products/import', [
            'file' => $this->csv('products.csv', "name,type,brand,sale_price\nWidget,product,Acme,10\n"),
        ])->headers->get('Location');

        $this->get('/products/import/preview/'.$this->tokenFrom((string) $location))
            ->assertSee('Invalid CSV header')
            ->assertSee('Unknown column');
    }

    public function test_missing_required_type_column_blocks_preview(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'No Type Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $location = $this->post('/products/import', [
            'file' => $this->csv('products.csv', "name,sku,sale_price\nWidget,W001,10\n"),
        ])->headers->get('Location');

        $this->get('/products/import/preview/'.$this->tokenFrom((string) $location))
            ->assertSee('Missing required column')
            ->assertSee('Type');
    }

    // --- happy-path: full mapping + relations -------------------------------------

    public function test_valid_import_creates_products_with_category_unit_tax_mapped(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Relations Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);
        $this->enableTax();

        $category = $this->makeCategory($business, 'Electronics');
        $unit = $this->makeUnit($business, 'Piece');
        $tax = $this->makeTax($business, 'VAT');

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv(
                'Widget,product,W001,12.5,Electronics gadget,electronics,piece,vat',
                'Consulting,service,,80,Phone consultation,,,',
            )),
        ])->headers->get('Location'));

        $this->post('/products/import/preview/'.$token, [])->assertRedirect(route('products.index'));

        $products = Product::query()->where('business_id', $business->id)->orderBy('id')->get();
        $this->assertCount(2, $products);

        $widget = $products[0];
        $this->assertSame('Widget', $widget->name);
        $this->assertSame('product', $widget->type->value);
        $this->assertSame('W001', $widget->sku);
        $this->assertSame('12.5000', $widget->sale_price);   // decimal:4 cast
        $this->assertSame('Electronics gadget', $widget->description);
        $this->assertSame($category->id, $widget->category_id);
        $this->assertSame($unit->id, $widget->unit_id);
        $this->assertSame($tax->id, $widget->tax_id);

        $consult = $products[1];
        $this->assertSame('service', $consult->type->value);
        $this->assertSame('80.0000', $consult->sale_price);
        $this->assertNull($consult->sku);
        $this->assertNull($consult->category_id);
    }

    public function test_case_insensitive_name_mapping_resolves(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Case Ins Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);
        $this->enableTax();

        $this->makeCategory($business, 'Electronics');
        $this->makeTax($business, 'VAT');

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Gadget,product,,10,, ELECTRONICS ,,  VAT  ')),
        ])->headers->get('Location'));

        $this->post('/products/import/preview/'.$token, [])->assertRedirect(route('products.index'));

        $product = Product::query()->where('business_id', $business->id)->first();
        $category = Category::query()->where('business_id', $business->id)->firstOrFail();
        $tax = Tax::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame($tax->id, $product->tax_id);
    }

    // --- tax gating ---------------------------------------------------------------

    public function test_tax_disabled_blocks_rows_with_tax_value(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Tax Off Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);
        // Tax NOT enabled — no Setting row = default false.

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Widget,product,,10,,,,')),
            // Above row has empty tax column — valid.  Now an invalid one:
        ])->headers->get('Location'));

        $this->get('/products/import/preview/'.$token)->assertSee('1 rows are ready to import.');

        // With a tax column set while disabled, it fails.
        $token2 = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Widget,product,,10,,, ,vat')),
        ])->headers->get('Location'));

        $this->get('/products/import/preview/'.$token2)
            ->assertSee('Tax is not enabled for this business.');

        $this->assertSame(0, Product::query()->where('business_id', $business->id)->count());
    }

    // --- reference not-found / ambiguity -----------------------------------------

    public function test_unknown_category_fails_the_row(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'No Cat Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Widget,product,,10,,nonexistent,,')),
        ])->headers->get('Location'));

        $this->get('/products/import/preview/'.$token)
            ->assertSee('Related Category not found');

        $this->assertSame(0, Product::query()->where('business_id', $business->id)->count());
    }

    public function test_category_from_another_business_is_not_found(): void
    {
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'A Co.');
        [$businessB] = $this->provision($user, 'B Co.');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);
        $this->makeCategory($businessA, 'Foo');

        $this->actIn($user, $businessB);

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Widget,product,,10,,foo,,')),
        ])->headers->get('Location'));

        $this->get('/products/import/preview/'.$token)->assertSee('Related Category not found');
        $this->assertSame(0, Product::query()->where('business_id', $businessB->id)->count());
    }

    public function test_ambiguous_category_names_are_unresolvable(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Ambig Cat Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $this->makeCategory($business, 'Dups');
        $this->makeCategory($business, 'Dups');

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Widget,product,,10,,dups,,')),
        ])->headers->get('Location'));

        $this->get('/products/import/preview/'.$token)->assertSee('Related Category not found');
    }

    public function test_unknown_unit_fails_the_row(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'No Unit Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Widget,product,,10,,,box,')),
        ])->headers->get('Location'));

        $this->get('/products/import/preview/'.$token)->assertSee('Related Unit not found');
    }

    // --- SKU uniqueness ------------------------------------------------------------

    public function test_duplicate_sku_within_file_blocks_the_import(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Dupe SKU Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv(
                'Widget A,product,W001,10,,,,',
                'Widget B,product,W001,20,,,,',
            )),
        ])->headers->get('Location'));

        $this->get('/products/import/preview/'.$token)->assertSee('Duplicate SKU in the same file.');
        $this->assertSame(0, Product::query()->where('business_id', $business->id)->count());
    }

    public function test_existing_sku_in_business_blocks_import(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Existing SKU Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);
        $this->makeExistingProduct($business, 'TAKEN');

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Widget,product,TAKEN,10,,,,')),
        ])->headers->get('Location'));

        $this->get('/products/import/preview/'.$token)
            ->assertSee('A product or service with this SKU already exists in this business.');

        $this->assertSame(1, Product::query()->where('business_id', $business->id)->count()); // only the existing one
    }

    public function test_different_business_can_import_the_same_sku(): void
    {
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'A Co.');
        [$businessB] = $this->provision($user, 'B Co.');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);
        $this->makeExistingProduct($businessA, 'DUAL');

        $this->actIn($user, $businessB);

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Widget,product,DUAL,10,,,,')),
        ])->headers->get('Location'));

        $this->post('/products/import/preview/'.$token, [])->assertRedirect(route('products.index'));

        $this->assertSame(1, Product::query()->where('business_id', $businessB->id)->count());
        $this->assertSame('DUAL', Product::query()->where('business_id', $businessB->id)->first()->sku);
    }

    // --- field validation ----------------------------------------------------------

    public function test_type_and_sale_price_validation(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Field Valid Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        // Blank type
        $t1 = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', "name,type,sale_price\nW,,10\n"),
        ])->headers->get('Location'));
        $this->get('/products/import/preview/'.$t1)->assertSee('The type is required.');

        // Invalid type
        $t2 = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', "name,type,sale_price\nW,gift,10\n"),
        ])->headers->get('Location'));
        $this->get('/products/import/preview/'.$t2)->assertSee('The type must be either');

        // Blank price
        $t3 = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', "name,type,sale_price\nW,product,\n"),
        ])->headers->get('Location'));
        $this->get('/products/import/preview/'.$t3)->assertSee('The sale price is required.');

        // Negative price
        $t4 = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', "name,type,sale_price\nW,product,-3\n"),
        ])->headers->get('Location'));
        $this->get('/products/import/preview/'.$t4)->assertSee('The sale price may not be negative.');

        // Too many decimal places
        $t5 = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', "name,type,sale_price\nW,product,1.12345\n"),
        ])->headers->get('Location'));
        $this->get('/products/import/preview/'.$t5)->assertSee('The sale price may have at most 4 decimal places.');

        // Scientific notation (numeric but fails regex)
        $t6 = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', "name,type,sale_price\nW,product,1e3\n"),
        ])->headers->get('Location'));
        $this->get('/products/import/preview/'.$t6)->assertSee('The sale price may have at most 4 decimal places.');

        $this->assertSame(0, Product::query()->where('business_id', $business->id)->count());
    }

    public function test_sale_price_is_normalized_and_integer_digit_limit_enforced(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Price Norm Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        // Too many integer digits (13 digits)
        $t1 = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', "name,type,sale_price\nW,product,9999999999999\n"),
        ])->headers->get('Location'));
        $this->get('/products/import/preview/'.$t1)->assertSee('The sale price is too large.');

        // Normal price normalized to 4 decimals
        $t2 = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', "name,type,sale_price\nW,product,12.5\n"),
        ])->headers->get('Location'));
        $this->post('/products/import/preview/'.$t2, [])->assertRedirect(route('products.index'));

        $product = Product::query()->where('business_id', $business->id)->first();
        $this->assertSame('12.5000', $product->sale_price);
    }

    public function test_name_and_description_max_lengths_enforced(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Max Len Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $longName = str_repeat('a', 101);
        $t1 = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', "name,type,sale_price\n{$longName},product,10\n"),
        ])->headers->get('Location'));
        $this->get('/products/import/preview/'.$t1)->assertSee('The name may not be longer than 100 characters.');

        $longDesc = str_repeat('x', 2001);
        $t2 = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', "name,type,sale_price,description\nW,product,10,{$longDesc}\n"),
        ])->headers->get('Location'));
        $this->get('/products/import/preview/'.$t2)->assertSee('The description may not be longer than 2000 characters.');
    }

    // --- all-or-nothing -----------------------------------------------------------

    public function test_invalid_rows_block_the_whole_import(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'All Or Nothing Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv(
                'Good,product,G001,10,,,,',
                'Bad,product,,missing_price,,,,',
            )),
        ])->headers->get('Location'));

        $this->get('/products/import/preview/'.$token)
            ->assertSee('1 of 2 rows cannot be imported.')
            ->assertDontSee('Confirm import');

        $this->post('/products/import/preview/'.$token, [])->assertRedirect(route('products.import.confirm', ['token' => $token]));
        $this->assertSame(0, Product::query()->where('business_id', $business->id)->count());
    }

    // --- leading zeros preserved ---------------------------------------------------

    public function test_sku_with_leading_zeros_is_preserved(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Leading Zero Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Widget,product,000042,10,,,,')),
        ])->headers->get('Location'));

        $this->post('/products/import/preview/'.$token, [])->assertRedirect(route('products.index'));
        $this->assertSame('000042', Product::query()->where('business_id', $business->id)->first()->sku);
    }

    // --- cancel / template ---------------------------------------------------------

    public function test_cancel_discards_file_and_token(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Cancel Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Widget,product,,10,,,,')),
        ])->headers->get('Location'));

        $this->delete('/products/import/preview/'.$token)
            ->assertRedirect(route('products.import'))
            ->assertSessionHas('status', 'Import cancelled. The uploaded file was discarded.');

        $this->assertSame(0, Product::query()->where('business_id', $business->id)->count());
        $this->assertEmpty(Storage::disk('local')->allFiles('imports'));
    }

    public function test_template_download_returns_exact_product_header(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Template Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $response = $this->get('/products/import/template')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="products-import-template.csv"');

        $this->assertStringStartsWith(
            "\xEF\xBB\xBFname,type,sku,sale_price,description,category,unit,tax",
            $response->getContent(),
        );
    }

    // --- queue --------------------------------------------------------------------

    public function test_large_import_is_dispatched_to_the_queue(): void
    {
        config(['business.import.queue_threshold' => 0]);
        Queue::fake();

        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Queue Co.');
        $this->enableProducts($business);
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Widget,product,W001,10,,,,')),
        ])->headers->get('Location'));

        $this->post('/products/import/preview/'.$token, [])
            ->assertRedirect(route('products.index'))
            ->assertSessionHas('status', 'Import started in the background. 1 records will be created.');

        Queue::assertPushed(ProcessImportJob::class);
        $this->assertNotEmpty(Storage::disk('local')->allFiles('imports'));
    }

    // --- token type guard ---------------------------------------------------------

    public function test_product_token_cannot_be_consumed_as_customer_import(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Type Guard Co.');
        $this->enableProducts($business);
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => 'customers'],
            ['enabled' => true],
        );
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Widget,product,,10,,,,')),
        ])->headers->get('Location'));

        $this->get('/customers/import/preview/'.$token)
            ->assertRedirect(route('customers.import'))
            ->assertSessionHas('status', 'This import session expired or is no longer available. Please start again.');

        $this->assertSame(0, Product::query()->where('business_id', $business->id)->count());
        $this->assertSame(0, Customer::query()->where('business_id', $business->id)->count());
    }

    public function test_token_from_business_a_cannot_be_consumed_in_business_b(): void
    {
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'A Co.');
        [$businessB] = $this->provision($user, 'B Co.');
        $this->enableProducts($businessA);
        $this->enableProducts($businessB);

        $this->actIn($user, $businessA);

        $token = $this->tokenFrom((string) $this->post('/products/import', [
            'file' => $this->csv('products.csv', $this->validCsv('Widget,product,,10,,,,')),
        ])->headers->get('Location'));

        $this->switchTo($businessB);

        $this->get('/products/import/preview/'.$token)
            ->assertRedirect(route('products.import'))
            ->assertSessionHas('status', 'This import session expired or is no longer available. Please start again.');

        $this->assertSame(0, Product::query()->where('business_id', $businessA->id)->count());
        $this->assertSame(0, Product::query()->where('business_id', $businessB->id)->count());
    }
}
