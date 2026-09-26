<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use App\Models\Business;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinalProductUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(CurrencySeeder::class);
        Storage::fake('public');
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.test',
            'password' => Hash::make('password'),
        ]);
    }

    public function test_onboarding_shell_matches_the_approved_sidebar_order(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->get('/onboarding')
            ->assertOk()
            ->assertSeeInOrder([
                'Dashboard',
                'Customers',
                'Sales',
                'Quotations',
                'Invoices',
                'Payments',
                'Expenses',
                'Products',
                'Inventory',
                'Purchasing',
                'POS',
                'Accounting',
                'Manufacturing',
                'CRM',
                'Reports',
                'Notifications',
                'Global Search',
                'Activity Log',
                'Smart Assistant',
                'SaaS / Businesses',
                'Users & Roles',
                'Modules',
                'Settings',
            ])
            ->assertSee('Welcome to BusinessOS')
            ->assertSee('Business Information')
            ->assertSee('Customize your workspace')
            ->assertSee('Enable Modules')
            ->assertSee('Workspace Preferences')
            ->assertSee('Quick Start Tips')
            ->assertSee('Your workspace is ready!');
    }

    public function test_sidebar_visual_metadata_matches_the_final_menu_reference(): void
    {
        $items = collect(config('navigation.items'))->keyBy('key');

        $this->assertSame('Professional Plan', config('product.plan_label'));
        $this->assertSame('system.notifications.index', $items['notifications']['route']);
        $this->assertSame('system.search.index', $items['global-search']['route']);
        $this->assertSame('system.activity.index', $items['activity-log']['route']);
        $this->assertSame('system.assistant.index', $items['smart-assistant']['route']);
        $this->assertSame('system.businesses.index', $items['saas-businesses']['route']);
        $this->assertSame('system.users-roles.index', $items['users-roles']['route']);
        $this->assertSame('system.modules.index', $items['modules']['route']);
        $this->assertSame('chart-line', $items['sales']['icon']);
        $this->assertSame('documents', $items['quotations']['icon']);
        $this->assertSame('clipboard-document-list', $items['invoices']['icon']);
        $this->assertSame('payment-card', $items['payments']['icon']);
        $this->assertSame('receipt-list', $items['expenses']['icon']);
        $this->assertSame('gift', $items['products']['icon']);
        $this->assertSame('building-storefront', $items['pos']['icon']);
        $this->assertSame('ledger', $items['accounting']['icon']);
        $this->assertSame('factory', $items['manufacturing']['icon']);
        $this->assertSame('contact-card', $items['crm']['icon']);
        $this->assertSame('history', $items['activity-log']['icon']);
        $this->assertSame('light-bulb', $items['smart-assistant']['icon']);
        $this->assertSame('saas', $items['saas-businesses']['icon']);
        $this->assertSame('module-grid', $items['modules']['icon']);
        $this->assertTrue($items['sales']['expandable']);
        $this->assertTrue($items['accounting']['expandable']);
        $this->assertTrue($items['crm']['expandable']);
    }

    public function test_first_business_onboarding_persists_company_modules_preferences_and_logo(): void
    {
        $user = $this->user();
        $logo = UploadedFile::fake()->image('logo.png', 256, 256)->size(100);

        $response = $this->actingAs($user)->post('/onboarding', [
            'name' => 'Acme Global Ltd.',
            'industry' => 'retail_wholesale',
            'country' => 'AF',
            'currency' => 'AFN',
            'timezone' => 'Asia/Kabul',
            'address' => 'Shahr-e-Naw, Kabul, Afghanistan',
            'phone' => '+93 70 123 4567',
            'email' => 'info@acmeglobal.af',
            'logo' => $logo,
            'locale' => 'fa',
            'appearance' => 'dark',
            'tax_enabled' => '1',
            'modules' => [
                'customers',
                'sales',
                'products',
                'inventory',
                'expenses',
                'reports',
                'smart_assistant',
                'notifications',
            ],
        ]);

        $business = Business::where('name', 'Acme Global Ltd.')->firstOrFail();

        $response->assertRedirect(route('app.home'));

        $this->assertDatabaseHas('business_memberships', [
            'business_id' => $business->id,
            'user_id' => $user->id,
        ]);

        foreach ([
            'dashboard',
            'settings',
            'customers',
            'sales',
            'products',
            'inventory',
            'expenses',
            'reports',
            'smart_assistant',
            'notifications',
        ] as $module) {
            $this->assertDatabaseHas('business_modules', [
                'business_id' => $business->id,
                'module_key' => $module,
                'enabled' => 1,
            ]);
        }

        $expectedSettings = [
            ['general', 'address', 'Shahr-e-Naw, Kabul, Afghanistan'],
            ['general', 'phone', '+93 70 123 4567'],
            ['general', 'email', 'info@acmeglobal.af'],
            ['general', 'industry', 'retail_wholesale'],
            ['general', 'country', 'AF'],
            ['general', 'tax_enabled', '1'],
            ['regional', 'timezone', 'Asia/Kabul'],
            ['regional', 'locale', 'fa'],
            ['regional', 'currency', 'AFN'],
            ['ui', 'appearance', 'dark'],
        ];

        foreach ($expectedSettings as [$group, $key, $value]) {
            $this->assertDatabaseHas('settings', [
                'business_id' => $business->id,
                'group' => $group,
                'key' => $key,
                'value' => $value,
            ]);
        }

        $logoPath = Setting::where('business_id', $business->id)
            ->where('group', 'document')
            ->where('key', 'logo_path')
            ->value('value');

        $this->assertIsString($logoPath);
        $this->assertStringStartsWith('business-documents/'.$business->id.'/', $logoPath);
        Storage::disk('public')->assertExists($logoPath);

        $membership = $user->memberships()->where('business_id', $business->id)->firstOrFail();
        $this->assertTrue($membership->roles()->where('slug', 'owner')->exists());
        $this->assertSame('fa', session(config('localization.session_key')));
    }

    public function test_dari_and_arabic_are_rtl_and_dari_is_presented_as_dari(): void
    {
        $this->assertSame('Dari', config('localization.supported.fa.label'));
        $this->assertSame('دری', config('localization.supported.fa.native'));
        $this->assertSame('rtl', SetLocale::direction('fa'));
        $this->assertSame('rtl', SetLocale::direction('ar'));
        $this->assertSame('ltr', SetLocale::direction('en'));
    }

    public function test_invalid_onboarding_module_locale_and_logo_are_rejected(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post('/onboarding', [
            'name' => 'Invalid Workspace',
            'country' => 'AF',
            'currency' => 'AFN',
            'timezone' => 'Asia/Kabul',
            'locale' => 'xx',
            'appearance' => 'light',
            'modules' => ['arbitrary-module'],
            'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg><script>alert(1)</script></svg>'),
        ])->assertSessionHasErrors([
            'locale',
            'modules.0',
            'logo',
        ]);

        $this->assertDatabaseMissing('businesses', ['name' => 'Invalid Workspace']);
    }
}
