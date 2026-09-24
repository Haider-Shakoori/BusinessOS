<?php

namespace Tests\Feature;

use App\Jobs\ProcessImportJob;
use App\Models\Business;
use App\Models\BusinessMembership;
use App\Models\BusinessModule;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ImportService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Customer CSV import (Batch 20).
 *
 * The whole import pipeline (upload -> preview -> confirm -> execute) is
 * create-only, preview-before-write and all-or-nothing. These tests pin
 * identity hardening (opaque session tokens bound to user/business/type), the
 * server-side revalidation, the tenant scoping of every created row, and the
 * RBAC (module + permission) guards around every route.
 */
class CustomerImportTest extends TestCase
{
    private const HEADER = 'name,company_name,email,phone,address,notes,opening_balance,opening_balance_date';

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

    private function enableCustomers(Business $business): void
    {
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => 'customers'],
            ['enabled' => true],
        );
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
     * Move to another business WITHOUT flushing the session, so an import token
     * created for business A can be probed from business B.
     */
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

    // --- guards: auth / module / permission -------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/customers/import')->assertRedirect(route('login'));
    }

    public function test_customers_module_must_be_enabled(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'No Module Co.');
        $this->actIn($user, $business);

        $this->get('/customers/import')->assertForbidden();
        $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $this->validCsv('Alice Red,,,,,,,')),
        ])->assertForbidden();
        $this->assertSame(0, Customer::query()->where('business_id', $business->id)->count());
    }

    public function test_viewer_without_manage_permission_is_forbidden_everywhere(): void
    {
        $user = $this->makeUser('Viewer Import');
        [$business] = $this->provision($user, 'Viewer Co.', 'viewer');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $this->get('/customers/import')->assertForbidden();
        $this->get('/customers/import/template')->assertForbidden();
        $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $this->validCsv('Alice Red,,,,,,,')),
        ])->assertForbidden();
        $this->assertSame(0, Customer::query()->where('business_id', $business->id)->count());
    }

    public function test_capability_grants_access_irrespective_of_role(): void
    {
        $user = $this->makeUser('Granted Viewer');
        [$business, , $roles] = $this->provision($user, 'Granted Co.', 'viewer');
        $this->enableCustomers($business);
        $manageId = Permission::where('name', 'customers.manage')->value('id');
        $roles['viewer']->permissions()->syncWithoutDetaching([$manageId]);
        $this->actIn($user, $business);

        $this->get('/customers/import')->assertOk();

        $location = $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $this->validCsv('Alice Red,,,,,,,')),
        ])->headers->get('Location');

        $this->get('/customers/import/preview/'.$this->tokenFrom((string) $location))->assertOk();
    }

    // --- upload validation -------------------------------------------------------

    public function test_upload_requires_a_file(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'No File Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $this->post('/customers/import', [])->assertSessionHasErrors(['file']);
    }

    public function test_upload_rejects_non_csv_extension(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Txt Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $this->post('/customers/import', [
            'file' => $this->csv('customers.txt', $this->validCsv('Alice Red,,,,,,,')),
        ])->assertSessionHasErrors(['file']);
    }

    public function test_binary_content_can_never_create_customers(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Fake Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', "\x89PNG\r\n\x1A\n".str_repeat('x', 64)),
        ]);

        // The MIME sniffers may or may not reject the upload, but records must
        // never be created from content that is not a parseable CSV.
        $this->assertSame(0, Customer::query()->where('business_id', $business->id)->count());
    }

    public function test_upload_accepts_a_text_plain_sniffed_csv_and_shows_row_errors(): void
    {
        // Regression (Batch 20 live QA): `finfo` classifies some perfectly valid
        // CSV content as `text/plain` (e.g. a leading empty first field). The
        // upload rules must accept it — `mimetypes` explicitly lists text/plain —
        // so the file-level gate must NOT include a strict `mimes:csv` rule that
        // contradicts that list. Row-level errors still surface on the preview.
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Text Plain Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $content = self::HEADER."\n"
            .",NoCompany,nobody@example.com,,,,,\n"
            ."Bad Email,Comp,not-an-email,,,,,\n";

        $location = $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $content),
        ])->assertSessionHasNoErrors(['file'])->headers->get('Location');

        $this->get($location)
            ->assertOk()
            ->assertSee('The field "Name" is required.')
            ->assertSee('The email must be a valid email address.')
            ->assertDontSee('Confirm import');

        $this->assertSame(0, Customer::query()->where('business_id', $business->id)->count());
    }

    public function test_upload_rejects_file_over_size_limit(): void
    {
        config(['business.import.max_file_kb' => 2]);

        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Big Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $big = $this->validCsv('Alice Red,,,,,,,').str_repeat('x', 3072);
        $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $big),
        ])->assertSessionHasErrors(['file']);
    }

    // --- header validation -------------------------------------------------------

    public function test_unknown_header_column_blocks_preview(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Unknown Header Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $location = $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', "name,company_name,home_city\nAlice Red,Acme,Paris\n"),
        ])->headers->get('Location');

        $this->get('/customers/import/preview/'.$this->tokenFrom((string) $location))
            ->assertOk()
            ->assertSee('Invalid CSV header')
            ->assertSee('Unknown column')
            ->assertDontSee('Confirm import');

        $this->assertSame(0, Customer::query()->where('business_id', $business->id)->count());
    }

    public function test_missing_required_name_column_blocks_preview(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Missing Header Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $location = $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', "company_name,email\nAcme,alice@example.com\n"),
        ])->headers->get('Location');

        $this->get('/customers/import/preview/'.$this->tokenFrom((string) $location))
            ->assertOk()
            ->assertSee('Missing required column')
            ->assertSee('Name');
    }

    public function test_duplicate_header_column_blocks_preview(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Dupe Header Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $location = $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', "name,name\nAlice,Red\n"),
        ])->headers->get('Location');

        $this->get('/customers/import/preview/'.$this->tokenFrom((string) $location))
            ->assertOk()
            ->assertSee('Duplicate column');
    }

    // --- parsing robustness ------------------------------------------------------

    public function test_bom_crlf_quoted_commas_and_blank_lines_parse(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Parsing Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $content = "\xEF\xBB\xBFname,notes,email\r\n"
            ."\r\n"
            ."\"Jane, Coffee\",\"Line one\nLine two\",jane@example.com\r\n"
            ."\r\n";

        $location = $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $content),
        ])->headers->get('Location');

        $this->get('/customers/import/preview/'.$this->tokenFrom((string) $location))
            ->assertOk()
            ->assertSee('are ready to import')
            ->assertSee('Jane, Coffee');

        $this->post('/customers/import/preview/'.$this->tokenFrom((string) $location), [])->assertRedirect(route('customers.index'));

        $customer = Customer::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame('Jane, Coffee', $customer->name);
        $this->assertSame("Line one\nLine two", $customer->notes);
        $this->assertSame('jane@example.com', $customer->email);
    }

    // --- all-or-nothing execution -------------------------------------------------

    public function test_valid_import_creates_customers_scoped_to_business(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Happy Path Co.');
        [$otherBusiness] = $this->provision($this->makeUser('Other'), 'Other Co.', 'viewer');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $this->validCsv(
                ' Alice Red ,Acme Ltd,alice@example.com,+93 20 123456,"Main St, Floor 2",Customer since 2020,000100.50,2026-09-01',
                'Bob Blue,,,,,,,',
            )),
        ])->headers->get('Location'));

        $this->get('/customers/import/preview/'.$token)
            ->assertOk()
            ->assertSee('2 rows are ready to import')
            ->assertSee('Confirm import');

        $this->post('/customers/import/preview/'.$token, [])
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('status', 'Import completed. 2 records created.');

        $customers = Customer::query()->where('business_id', $business->id)->orderBy('id')->get();
        $this->assertCount(2, $customers);
        $this->assertSame('Alice Red', $customers[0]->name);            // trimmed
        $this->assertSame('Acme Ltd', $customers[0]->company_name);
        $this->assertSame('alice@example.com', $customers[0]->email);
        $this->assertSame('Main St, Floor 2', $customers[0]->address);  // quoted comma
        $this->assertSame('100.5000', $customers[0]->opening_balance);  // leading zeros normalized to DECIMAL(16,4) storage
        $this->assertSame('2026-09-01', $customers[0]->opening_balance_date->toDateString());
        $this->assertSame('Bob Blue', $customers[1]->name);

        // Nothing leaked into the other business, the staged file is deleted,
        // and the token is consumed.
        $this->assertSame(0, Customer::query()->where('business_id', $otherBusiness->id)->count());
        $this->assertEmpty(Storage::disk('local')->allFiles('imports'));
        $this->assertEmpty(session(ImportService::TOKEN_SESSION_KEY) ?? []);
    }

    public function test_any_invalid_row_blocks_the_whole_import(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'All Or Nothing Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $this->validCsv(
                'Alice Red,,,,,,,',
                'Bob Blue,,not-an-email,,,,,',
            )),
        ])->headers->get('Location'));

        $this->get('/customers/import/preview/'.$token)
            ->assertOk()
            ->assertSee('1 of 2 rows cannot be imported.')
            ->assertSee('Row 3 — The email must be a valid email address.')
            ->assertDontSee('Confirm import');

        // Execute with a broken file still creates nothing (revalidation).
        $this->post('/customers/import/preview/'.$token, [])
            ->assertRedirect(route('customers.import.confirm', ['token' => $token]));

        $this->assertSame(0, Customer::query()->where('business_id', $business->id)->count());
    }

    public function test_invalid_balance_and_date_are_row_errors(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Ledger Rows Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $this->validCsv(
                'Aaq,,,,,,-50,',
                'Bab,,,,,,,2026-13-99',
            )),
        ])->headers->get('Location'));

        $this->get('/customers/import/preview/'.$token)
            ->assertOk()
            ->assertSee('The opening balance must be a valid amount')
            ->assertSee('The opening balance date must be a valid date');

        $this->assertSame(0, Customer::query()->where('business_id', $business->id)->count());
    }

    // --- identity hardening --------------------------------------------------------

    public function test_token_from_business_a_cannot_be_consumed_in_business_b(): void
    {
        $user = $this->makeUser();
        [$businessA] = $this->provision($user, 'A Co.');
        [$businessB] = $this->provision($user, 'B Co.');
        $this->enableCustomers($businessA);
        $this->enableCustomers($businessB);
        $this->actIn($user, $businessA);

        $token = $this->tokenFrom((string) $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $this->validCsv('Alice Red,,,,,,,')),
        ])->headers->get('Location'));

        $this->switchTo($businessB);

        $this->get('/customers/import/preview/'.$token)
            ->assertRedirect(route('customers.import'))
            ->assertSessionHas('status', 'This import session expired or is no longer available. Please start again.');

        $this->assertSame(0, Customer::query()->where('business_id', $businessA->id)->count());
        $this->assertSame(0, Customer::query()->where('business_id', $businessB->id)->count());
        $this->assertEmpty(Storage::disk('local')->allFiles('imports'));
    }

    public function test_customer_token_is_not_consumable_as_a_product_import(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Type Mix Co.');
        $this->enableCustomers($business);
        BusinessModule::updateOrCreate(
            ['business_id' => $business->id, 'module_key' => 'products'],
            ['enabled' => true],
        );
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $this->validCsv('Alice Red,,,,,,,')),
        ])->headers->get('Location'));

        $this->get('/products/import/preview/'.$token)
            ->assertRedirect(route('products.import'))
            ->assertSessionHas('status', 'This import session expired or is no longer available. Please start again.');

        $this->assertSame(0, Customer::query()->where('business_id', $business->id)->count());
    }

    public function test_lost_staged_file_invalidates_the_session(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Lost File Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $this->validCsv('Alice Red,,,,,,,')),
        ])->headers->get('Location'));

        // Simulate the staged file disappearing between upload and preview.
        $tokens = session(ImportService::TOKEN_SESSION_KEY);
        Storage::disk('local')->delete($tokens[$token]['path']);

        $this->get('/customers/import/preview/'.$token)
            ->assertRedirect(route('customers.import'))
            ->assertSessionHas('status', 'This import session expired or is no longer available. Please start again.');
    }

    public function test_a_newer_upload_replaces_the_pending_token(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Replace Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $first = $this->tokenFrom((string) $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $this->validCsv('Alice Red,,,,,,,')),
        ])->headers->get('Location'));

        $second = $this->tokenFrom((string) $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $this->validCsv('Bob Blue,,,,,,,')),
        ])->headers->get('Location'));

        $this->assertNotSame($first, $second);

        // The first token is dead and its file was discarded.
        $this->get('/customers/import/preview/'.$first)
            ->assertRedirect(route('customers.import'))
            ->assertSessionHas('status', 'This import session expired or is no longer available. Please start again.');

        $this->post('/customers/import/preview/'.$second, [])->assertRedirect(route('customers.index'));

        $this->assertSame(['Bob Blue'], Customer::query()->where('business_id', $business->id)->pluck('name')->all());
    }

    // --- cancel / template / queue -------------------------------------------------

    public function test_cancel_discards_file_and_token(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Cancel Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $this->validCsv('Alice Red,,,,,,,')),
        ])->headers->get('Location'));

        $this->delete('/customers/import/preview/'.$token)
            ->assertRedirect(route('customers.import'))
            ->assertSessionHas('status', 'Import cancelled. The uploaded file was discarded.');

        $this->assertSame(0, Customer::query()->where('business_id', $business->id)->count());
        $this->assertEmpty(Storage::disk('local')->allFiles('imports'));
    }

    public function test_template_download_returns_csv_with_exact_header(): void
    {
        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Template Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $response = $this->get('/customers/import/template')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="customers-import-template.csv"');

        $content = $response->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFname,company_name,email,phone,address,notes,opening_balance,opening_balance_date", $content);
    }

    public function test_large_import_is_dispatched_to_the_queue(): void
    {
        config(['business.import.queue_threshold' => 0]);
        Queue::fake();

        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Queue Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $this->validCsv('Alice Red,,,,,,,')),
        ])->headers->get('Location'));

        $this->post('/customers/import/preview/'.$token, [])
            ->assertRedirect(route('customers.index'))
            ->assertSessionHas('status', 'Import started in the background. 1 records will be created.');

        Queue::assertPushed(ProcessImportJob::class);

        // The file is intentionally kept for the worker (token is released).
        $this->assertNotEmpty(Storage::disk('local')->allFiles('imports'));
        $this->assertSame(0, Customer::query()->where('business_id', $business->id)->count());
    }

    public function test_row_cap_is_enforced_during_scan(): void
    {
        config(['business.import.max_rows' => 1]);

        $user = $this->makeUser();
        [$business] = $this->provision($user, 'Cap Co.');
        $this->enableCustomers($business);
        $this->actIn($user, $business);

        $token = $this->tokenFrom((string) $this->post('/customers/import', [
            'file' => $this->csv('customers.csv', $this->validCsv(
                'Alice Red,,,,,,,',
                'Bob Blue,,,,,,,',
            )),
        ])->headers->get('Location'));

        $this->get('/customers/import/preview/'.$token)
            ->assertOk()
            ->assertSee('The file has more than 1 rows. Split it and upload again.');

        $this->assertSame(0, Customer::query()->where('business_id', $business->id)->count());
    }
}
