<?php

namespace App\Providers;

use App\Models\Asset;
use App\Models\Platform;
use App\Policies\CatalogPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Guzzle's default is no timeout at all: one hung provider would pin
        // a PHP worker indefinitely (free hosting often has a single one).
        // Calls that need longer (Gemini) still set their own ->timeout().
        //
        // IPv4 only: alwaysdata egresses over IPv6 by default, and Google
        // rejects that address outright (403, "User location is not
        // supported" — confirmed live 2026-09-28) while the IPv4 one is
        // accepted. Every provider used here was checked to serve IPv4.
        Http::globalOptions(['timeout' => 10, 'connect_timeout' => 5, 'force_ip_resolve' => 'v4']);

        // Every CoinGecko call site goes through this, so the optional Demo
        // key (same host, own quota instead of the server IP's) is attached
        // in one place — as a header, never in the URL. withKey: false is
        // the keyless public API: 5-15 calls/min but no monthly cap, unlike
        // the Demo key's 10,000 calls/month (see CoinGeckoPriceProvider::refresh()).
        Http::macro('coingecko', fn (bool $withKey = true) => Http::baseUrl('https://api.coingecko.com/api/v3')
            ->withHeaders(array_filter(['x-cg-demo-api-key' => $withKey ? config('services.coingecko.key') : null])));

        // One policy for both halves of the shared catalog: same ownership
        // rules, and auto-discovery would look for AssetPolicy/PlatformPolicy.
        Gate::policy(Asset::class, CatalogPolicy::class);
        Gate::policy(Platform::class, CatalogPolicy::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // /login, /register, /forgot-password, /reset-password: public,
        // unauthenticated, and outside the 'api' limiter above (routes/web.php,
        // not routes/api.php) — without this, nothing capped login brute-force
        // attempts or spammed registrations (each one also triggers a real
        // Resend email, so it's a quota-exhaustion vector too).
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Named rather than inline 'throttle:10,1': inline limits key on
        // the user alone, so every route using one shares a single counter
        // (10 Gemini parses would also have blocked logo uploads and even
        // PATCH /user's 'throttle:6,1'). A named limiter's key includes its
        // name, so each budget is independent.
        RateLimiter::for('catalog-writes', fn (Request $request) => Limit::perMinute(10)->by($request->user()->id));
        RateLimiter::for('ai-parse', fn (Request $request) => Limit::perMinute(10)->by($request->user()->id));
        // The export walks every table tied to the account: heavier than
        // any other read, and nobody needs it more than a few times.
        RateLimiter::for('data-export', fn (Request $request) => Limit::perMinute(3)->by($request->user()->id));

        // Single source of truth for password strength: RegisterRequest,
        // UpdatePasswordRequest and ResetPasswordRequest all call
        // Password::defaults(). Follows NIST SP 800-63B: 15 chars minimum
        // because the password is the sole authentication factor, at least
        // 64 accepted, and deliberately NO composition rules (mixed case,
        // digits, symbols) — NIST says verifiers SHALL NOT impose them.
        // uncompromised() checks HaveIBeenPwned through k-anonymity (only the
        // first 5 chars of the SHA-1 leave this server) and fails open if
        // their API is down, so an outage there can't block registrations.
        Password::defaults(fn () => Password::min(15)->max(64)->uncompromised());

        // Laravel's default reset-link points at this backend, which has no
        // password-reset page — the SPA does (mail.brand.frontend_url, the
        // first FRONTEND_URLS entry).
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontendUrl = config('mail.brand.frontend_url');

            return "{$frontendUrl}/reset-password?token={$token}&email=".urlencode($user->email);
        });
    }
}
