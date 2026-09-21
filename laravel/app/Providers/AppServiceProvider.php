<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Laravel's default reset-link points at this backend, which has no
        // password-reset page — the SPA does. First entry of FRONTEND_URLS
        // (same env var CORS already reads) is the frontend's own origin.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $frontendUrl = config('cors.allowed_origins')[0] ?? 'http://localhost:5173';

            return "{$frontendUrl}/reset-password?token={$token}&email=".urlencode($user->email);
        });
    }
}
