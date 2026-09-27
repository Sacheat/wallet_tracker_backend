<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every response here is per-user financial data or auth state. Symfony's
 * default 'no-cache, private' still lets the browser write it to disk and
 * to the back/forward cache; 'no-store' forbids storing it at all.
 *
 * Not Laravel's built-in 'cache.headers' (SetCacheHeaders): it skips
 * non-2xx responses and non-GET methods, and a 422 echoing back submitted
 * amounts is just as sensitive as a 200.
 */
class PreventBrowserCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
