<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use App\Support\SafeRedirect;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    private string $password;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
        // Fresh in-memory connection, never migrate:fresh or a developer database.
        $this->artisan('migrate', ['--path' => 'database/migrations/0001_01_01_000000_create_users_table.php', '--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_09_11_000001_create_businesses_table.php', '--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_09_11_000002_create_business_memberships_table.php', '--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_09_11_000003_create_roles_table.php', '--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_09_11_000004_create_permissions_table.php', '--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_09_11_000005_create_role_permission_table.php', '--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_09_11_000006_create_business_membership_role_table.php', '--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_09_11_000007_create_business_modules_table.php', '--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_09_11_000008_create_settings_table.php', '--force' => true])->assertExitCode(0);
        $this->password = Str::random(32);
    }

    private function user(): User
    {
        return User::create(['name' => 'Auth Test', 'email' => 'auth@example.test', 'password' => $this->password]);
    }

    /*
     * Give a user a business membership so /app is reachable (Batch 6).
     */
    private function attachBusiness(User $user): Business
    {
        $business = Business::create(['name' => 'Auth Workspace']);
        $user->businesses()->attach($business);

        return $business;
    }

    public function test_login_rotates_session_and_supports_safe_intended_and_remember_me(): void
    {
        $user = $this->user();
        $this->attachBusiness($user);
        $this->withSession(['url.intended' => config('app.url').'/app?from=login']);
        $oldId = session()->getId();
        $oldToken = session()->token();
        $response = $this->post('/login', ['email' => $user->email, 'password' => $this->password, 'remember' => '1']);
        $response->assertRedirect('/app?from=login')->assertCookie(auth()->guard()->getRecallerName());
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->assertNotEmpty($user->fresh()->remember_token);
        $this->get('/app')->assertOk()->assertSee('Auth Test')->assertDontSee('Dev Manager')->assertDontSee('app-preview');
    }

    public function test_invalid_login_is_generic_throttled_and_never_flashes_password(): void
    {
        $user = $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->from('/login')->post('/login', ['email' => $user->email, 'password' => Str::random(32)])
                ->assertSessionHasErrors(['email' => __('auth.failed')]);
        }
        $this->post('/login', ['email' => $user->email, 'password' => $this->password])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertNull(session()->getOldInput('password'));
        $this->assertStringContainsString('Too many', session('errors')->first('email'));
    }

    public function test_auth_and_guest_route_protection_and_scope(): void
    {
        $this->get('/app')->assertRedirect('/login');
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
        $this->get('/verify-email')->assertNotFound();
        $this->get('/app-preview')->assertNotFound();
        $this->get('/ui-preview')->assertNotFound();
        $this->actingAs($this->user());
        foreach (['/login', '/forgot-password', '/reset-password/example'] as $url) {
            $this->get($url)->assertRedirect('/app');
        }
        $this->get('/logout')->assertStatus(405);
    }

    public function test_logout_invalidates_session_and_preserves_only_locale(): void
    {
        $this->actingAs($this->user())->withSession(['locale' => 'fa', 'private_marker' => 'remove']);
        $oldId = session()->getId();
        $oldToken = session()->token();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertNotSame($oldId, session()->getId());
        $this->assertNotSame($oldToken, session()->token());
        $this->assertNull(session('private_marker'));
        $this->assertSame('fa', session('locale'));
        $this->get('/app')->assertRedirect('/login');
    }

    public function test_csrf_is_enforced_for_login_logout_and_password_posts(): void
    {
        $this->app['env'] = 'local'; // Enable the real CSRF middleware in this test.
        foreach (['/login', '/forgot-password', '/reset-password'] as $url) {
            $this->post($url)->assertStatus(419);
        }
        $this->actingAs($this->user());
        $this->post('/logout')->assertStatus(419);
        $this->assertAuthenticated();
    }

    public function test_reset_request_uses_broker_hashes_token_and_hides_account_existence(): void
    {
        Notification::fake();
        $user = $this->user();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', __('auth.reset_link_sent'));
        Notification::assertSentTo($user, ResetPassword::class, function ($notice) use ($user) {
            $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
            $this->assertNotSame($notice->token, $stored);
            $this->assertTrue(Hash::check($notice->token, $stored));

            return true;
        });
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status', __('auth.reset_link_sent'));
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        $this->post('/forgot-password', ['email' => 'missing@example.test'])->assertSessionHas('status', __('auth.reset_link_sent'));
        $this->post('/forgot-password', ['email' => 'missing@example.test']);
        $this->post('/forgot-password', ['email' => 'missing@example.test']);
        $this->post('/forgot-password', ['email' => 'missing@example.test'])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_password_reset_checks_confirmation_rotates_credentials_and_consumes_token(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);
        $newPassword = Str::random(32);
        $data = ['email' => $user->email, 'token' => $token, 'password' => $newPassword, 'password_confirmation' => $newPassword];
        $this->post('/reset-password', array_replace($data, ['password_confirmation' => 'mismatch']))->assertSessionHasErrors('password');
        $this->assertNull(session()->getOldInput('token'));
        $this->assertNull(session()->getOldInput('password'));
        $oldHash = $user->password;
        $oldRemember = $user->remember_token;
        $this->post('/reset-password', $data)->assertRedirect('/login')->assertSessionHas('status', __('passwords.reset'));
        $user->refresh();
        $this->assertTrue(Hash::check($newPassword, $user->password));
        $this->assertNotSame($oldHash, $user->password);
        $this->assertNotSame($oldRemember, $user->remember_token);
        $this->assertGuest();
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->post('/reset-password', $data)->assertSessionHasErrors(['email' => __('passwords.token')]);
        $this->post('/login', ['email' => $user->email, 'password' => $newPassword])->assertRedirect('/app');
    }

    public function test_expired_reset_token_is_rejected(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);
        DB::table('password_reset_tokens')->update(['created_at' => now()->subMinutes(61)]);
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => $this->password, 'password_confirmation' => $this->password])
            ->assertSessionHasErrors(['email' => __('passwords.token')]);
    }

    public function test_changed_password_revokes_existing_authenticated_session(): void
    {
        $user = $this->user();
        $this->attachBusiness($user);
        $this->actingAs($user)->get('/app')->assertOk();
        $user->forceFill(['password' => Hash::make(Str::random(32))])->save();
        $this->get('/app')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_redirects_reject_external_and_protocol_relative_destinations(): void
    {
        foreach (['https://outside.test', '//outside.test', '/%2foutside.test', '/\\outside.test', 'https://localhost.evil.test/app', 'javascript:alert(1)', '/%0aoutside'] as $target) {
            $this->assertSame('/app', SafeRedirect::path($target));
        }
        $user = $this->user();
        $this->withSession(['url.intended' => 'https://outside.test'])->post('/login', ['email' => $user->email, 'password' => $this->password])->assertRedirect('/app');
        $this->withHeader('referer', 'https://outside.test')->get('/locale/fa')->assertRedirect('/');
        $this->post('/logout');
        $this->withHeader('referer', 'https://outside.test')->post('/login', [])->assertRedirect('/login');
        $this->withHeader('referer', 'https://outside.test')->post('/forgot-password', [])->assertRedirect('/forgot-password');
        $this->withHeader('referer', 'https://outside.test')->post('/reset-password', [])->assertRedirect('/reset-password/invalid?email=');
    }

    public function test_auth_views_and_validation_are_localized_in_all_supported_locales(): void
    {
        foreach (['en' => 'ltr', 'fa' => 'rtl', 'ar' => 'rtl'] as $locale => $direction) {
            foreach (['/login', '/forgot-password', '/reset-password/example?email=auth@example.test'] as $url) {
                $this->withSession(['locale' => $locale])->get($url)->assertOk()
                    ->assertSee('lang="'.$locale.'"', false)->assertSee('dir="'.$direction.'"', false)
                    ->assertSee('bos-theme')->assertSee('dark:bg-gray-900')->assertDontSee('auth.email');
            }
            $this->post('/login', ['email' => 'not-an-email'])->assertSessionHasErrors([
                'email' => __('validation.email'),
                'password' => __('validation.required', ['attribute' => __('validation.attributes.password')]),
            ]);
        }
    }

    public function test_reset_mail_is_localized_uses_configured_origin_and_never_log_transport(): void
    {
        $user = $this->user();
        $this->app->setLocale('fa');
        config(['app.url' => 'https://businessos.example', 'auth.password_mailer' => 'array']);
        $notice = new ResetPassword(Str::random(64));
        $message = $notice->toMail($user);
        $this->assertSame('array', $message->mailer);
        $this->assertStringStartsWith('https://businessos.example/reset-password/', $message->viewData['url']);
        $this->assertStringContainsString('بازنشانی', view($message->view, $message->viewData)->render());
        config(['auth.password_mailer' => 'log']);
        $this->expectException(\LogicException::class);
        $notice->toMail($user);
    }
}
