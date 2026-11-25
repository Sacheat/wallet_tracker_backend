<?php

use App\Http\Middleware\PreventBrowserCaching;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->throttleApi();
        $middleware->append(PreventBrowserCaching::class);
        // The reverse proxy (nginx) runs on the same machine: only loopback
        // is trusted, so $request->ip() — the 'auth'/'api' rate limiters and
        // sessions.ip_address — is the real client, while a client-sent
        // X-Forwarded-For on a direct hit stays ignored. Only For/Proto:
        // nginx passes a client's X-Forwarded-Host through untouched.
        $middleware->trustProxies(
            at: ['127.0.0.1', '::1'],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );
        // One-click unsubscribe (RFC 8058): the mail client POSTs with no
        // session nor CSRF token; the URL's signature protects it instead.
        $middleware->validateCsrfTokens(except: ['email/unsubscribe/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always JSON, even without 'Accept: application/json': this app
        // has no HTML pages, so the default 302 to route('login') (a
        // POST-only route) led nowhere — an unauthenticated GET /user
        // now gets a plain 401, same as the SPA already sees.
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
