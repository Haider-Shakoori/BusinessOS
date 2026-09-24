<?php

namespace App\Providers;

use App\Models\User;
use App\Services\BusinessContext;
use App\Services\BusinessSettings;
use App\Services\DocumentNumberService;
use App\Services\InvoiceService;
use App\Services\MembershipAuthorization;
use App\Services\ModuleManager;
use App\Services\PaymentService;
use App\Services\QuotationCalculator;
use App\Services\QuotationService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One BusinessContext per request lifecycle so memberships and the
        // resolved current business are computed once and reused safely.
        $this->app->scoped(BusinessContext::class);

        // Authorization is stateless given the (scoped) current business,
        // so it may share the same request lifecycle as the context.
        $this->app->scoped(MembershipAuthorization::class);

        // Module availability is per-request (current-business-dependent)
        // and shares the same lifecycle as the context it depends on.
        $this->app->scoped(ModuleManager::class);

        // Settings are per-request and current-business-dependent, mirroring
        // the context they resolve. The service is the only authority over the
        // settings table (no direct Eloquent access from controllers/views).
        $this->app->scoped(BusinessSettings::class);

        // Document numbering (Batch 13) shares the current request lifecycle
        // because it must resolve the current business and its settings. It is
        // the only authority over the document_number_sequences table.
        $this->app->scoped(DocumentNumberService::class);

        // Quotation arithmetic (Batch 14) is a stateless pure calculator, but
        // QuotationService is the authority over the write path (number
        // allocation + atomically-stored header/items), so it shares the same
        // current-business lifecycle as the services it composes.
        $this->app->singleton(QuotationCalculator::class);
        $this->app->scoped(QuotationService::class);

        // Invoices (Batch 15) compose DocumentNumberService, the shared
        // QuotationCalculator and the current business context/settings on every
        // write and on quotation->invoice conversion, so they share the same
        // current-business lifecycle.
        $this->app->scoped(InvoiceService::class);

        // Payments (Batch 16) compose DocumentNumberService on every record and
        // rely on the current-business global scopes for tenancy, so they share
        // the same request lifecycle. The service is the only authority over
        // payment recording/reversal and invoice financial reconciliation.
        $this->app->scoped(PaymentService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Permission check convention (Batch 7): every registered permission
        // key is a Laravel Gate ability evaluated inside the current business.
        //
        //   Gate::allows('settings.manage')  ->  @can('settings.manage')  ->
        //   Middleware 'permission:settings.manage'
        //
        // Abilities that are NOT permission keys fall through untouched so the
        // convention composes with future Policies. Unknown guests or a missing
        // current business deny the check.
        $permissionKeys = collect(config('permissions.groups', []))->flatten()->values()->all();

        Gate::before(function (?User $user, string $ability) use ($permissionKeys) {
            if (! in_array($ability, $permissionKeys, true)) {
                return null;
            }

            return app(MembershipAuthorization::class)->can($ability, $user);
        });

        foreach (['auth-login' => 30, 'auth-password' => 5] as $name => $attempts) {
            RateLimiter::for($name, function (Request $request) use ($attempts) {
                return Limit::perMinute($attempts)->by($request->ip())->response(
                    fn (Request $request, array $headers) => response()->view('auth.throttled', [
                        'seconds' => $headers['Retry-After'] ?? 60,
                    ], 429, $headers),
                );
            });
        }

        ResetPassword::toMailUsing(function ($user, string $token) {
            $mailer = config('auth.password_mailer');
            // Reset links contain credentials: never send them to a logging transport.
            if (! in_array(config("mail.mailers.$mailer.transport"), ['smtp', 'sendmail', 'array'], true)) {
                throw new \LogicException('Password reset mail requires a non-logging mail transport.');
            }

            $url = rtrim(config('app.url'), '/').route('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], false);

            return (new MailMessage)->mailer($mailer)
                ->subject(__('auth.reset_password'))
                ->view('auth.emails.reset-password', [
                    'url' => $url,
                    'minutes' => config('auth.passwords.users.expire'),
                ]);
        });
    }
}
