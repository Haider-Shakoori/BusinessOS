<?php

namespace App\Http\Middleware;

use App\Services\ActivityRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordActivity
{
    public function __construct(private readonly ActivityRecorder $recorder)
    {
        //
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() < 400) {
            $this->recorder->record($request);
        }

        return $response;
    }
}
