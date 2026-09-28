<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\DataExportController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

Route::get('/', function () {
    return view('welcome');
});

// Session-based auth for the SPA (Sanctum stateful): CSRF-protected via the
// 'web' middleware group, the frontend must call GET /sanctum/csrf-cookie first.
// 'throttle:auth' (AppServiceProvider, 5/min/IP): these 4 are public and
// unauthenticated, so nothing else caps login brute-force or registration/
// reset-email spam — they sit outside the 'api' limiter (routes/api.php only).
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
// Named 'login': Laravel's default guest-redirect middleware calls route('login')
// to build the AuthenticationException's redirect target even for API requests
// (before deciding whether to actually respond with a redirect or JSON), so it
// must resolve to something or every 401 crashes into a 500.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth')->name('login');
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:auth');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:auth');

// Signed link from the verification email — the browser navigates here
// directly (not an XHR call), so it needs 'auth' (session cookie) rather
// than the JSON-oriented routes below, and 'signed' validates id/hash/
// expires/signature instead of a request body.
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['auth', AuthenticateSession::class, 'signed:relative', 'throttle:6,1'])
    ->name('verification.verify');
// See EmailVerificationController::notice() — must resolve to something or
// EnsureEmailIsVerified crashes on a non-JSON request, same as 'login'.
Route::get('/email/verify', [EmailVerificationController::class, 'notice'])
    ->middleware(['auth', AuthenticateSession::class])
    ->name('verification.notice');

// AuthenticateSession: the same check Sanctum already runs on /api/*
// (sanctum.middleware.authenticate_session). It stores a hash of the
// password in the session and kills any session whose hash no longer
// matches — without it here, a session opened before a password change or
// reset kept working on these routes (read the profile, rename, upload an
// avatar) as long as it avoided /api/*. Found in a security audit.
Route::middleware(['auth', AuthenticateSession::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
    // GDPR portability. Not under /api: it must stay reachable while the
    // email is unverified (the 'verified' middleware only guards /api/*).
    Route::get('/user/export', DataExportController::class)->middleware('throttle:data-export');
    // Same cap as the resend route below: an email change sends a
    // verification mail to whatever address was typed.
    Route::patch('/user', [ProfileController::class, 'update'])->middleware('throttle:6,1');
    // Both require current_password: without a throttle, a hijacked session
    // gets unlimited guesses at it (found in a security audit — every other
    // sensitive route in this file already has one, these two didn't).
    Route::patch('/user/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:6,1');
    Route::post('/user/avatar', [AvatarController::class, 'store']);
    Route::delete('/user/avatar', [AvatarController::class, 'destroy']);
    Route::delete('/user', [ProfileController::class, 'destroy'])->middleware('throttle:6,1');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});
