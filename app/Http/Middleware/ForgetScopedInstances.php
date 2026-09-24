<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForgetScopedInstances
{
    public function __construct(private Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->app->forgetScopedInstances();

        // Scoped services live on the container, but a resolved Route keeps its
        // own cached controller instance (Route::getController()) that captures
        // those services at construction time. Between HTTP requests in a shared
        // process (PHPUnit, Octane, workers) that cache must be flushed too, or
        // the controller keeps a stale BusinessContext/BusinessSettings from the
        // previous request — exactly the leak a per-request scope is meant to
        // prevent. Flushing every controller re-establishes a true per-request
        // lifecycle for the whole service graph (see docs/DECISIONS.md).
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }

        return $next($request);
    }
}
