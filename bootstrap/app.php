<?php

use App\Http\Middleware\ActivityLogMiddleware;
use App\Http\Middleware\AuthenticateFieldPulseIntegration;
use App\Http\Middleware\EnsureBusinessSelected;
use App\Http\Middleware\EnsureModule;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTaxEnabled;
use App\Http\Middleware\ForgetScopedInstances;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(ForgetScopedInstances::class);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('app.home'));
        $middleware->web(append: [
            SetLocale::class,
            ActivityLogMiddleware::class,
        ]);
        $middleware->alias([
            'business-selected' => EnsureBusinessSelected::class,
            'module' => EnsureModule::class,
            'permission' => EnsurePermission::class,
            'super-admin' => EnsureSuperAdmin::class,
            'tax-enabled' => EnsureTaxEnabled::class,
            'fieldpulse.integration' => AuthenticateFieldPulseIntegration::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'token']);
    })->create();
