<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DebugHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('Headers received', $request->headers->all());
        Log::info('URL seen by Laravel', [
            'scheme' => $request->getScheme(),
            'host'   => $request->getHost(),
            'port'   => $request->getPort(),
            'full'   => $request->fullUrl(),
        ]);

        return $next($request);
    }
}
