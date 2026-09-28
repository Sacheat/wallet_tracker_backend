<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * In production every request reaches this backend through the Vercel
 * rewrite, from AWS addresses that change per request and are shared with
 * any AWS customer, so they can't be allow-listed. Vercel instead adds a
 * shared secret header (vercel.json transform, value from a Vercel env var):
 * only a request carrying it gets its X-Forwarded-For trusted, so
 * $request->ip() — and with it the 'auth'/'api' rate limiters and
 * sessions.ip_address — is the real client again. Anything else is a direct
 * hit on alwaysdata, where X-Forwarded-For is attacker-controlled: rejected.
 *
 * Only For/Proto are trusted, not Host/Port/Prefix: the backend must keep
 * seeing its own host, and absolute URLs are built from APP_URL anyway.
 *
 * With no PROXY_SECRET configured (local dev, tests) nothing is trusted and
 * nothing is rejected — the parent's reset alone, same as stock Laravel.
 */
class TrustVercelProxy extends TrustProxies
{
    public const HEADER = 'X-Proxy-Secret';

    public function handle(Request $request, Closure $next)
    {
        $request::setTrustedProxies([], $this->getTrustedHeaderNames());

        $secret = config('app.proxy_secret');

        if (filled($secret)) {
            abort_unless(hash_equals($secret, (string) $request->header(self::HEADER)), 403);

            $request::setTrustedProxies(
                [$request->server->get('REMOTE_ADDR')],
                Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
            );
        }

        return $next($request);
    }
}
