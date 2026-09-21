<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvatarController;
use App\Http\Controllers\EmailVerificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Session-based auth for the SPA (Sanctum stateful): CSRF-protected via the
// 'web' middleware group, the frontend must call GET /sanctum/csrf-cookie first.
Route::post('/register', [AuthController::class, 'register']);
// Named 'login': Laravel's default guest-redirect middleware calls route('login')
// to build the AuthenticationException's redirect target even for API requests
// (before deciding whether to actually respond with a redirect or JSON), so it
// must resolve to something or every 401 crashes into a 500.
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink']);
Route::post('/reset-password', [PasswordResetController::class, 'reset']);

// Signed link from the verification email — the browser navigates here
// directly (not an XHR call), so it needs 'auth' (session cookie) rather
// than the JSON-oriented routes below, and 'signed' validates id/hash/
// expires/signature instead of a request body.
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware(['auth', 'signed', 'throttle:6,1'])
    ->name('verification.verify');
// See EmailVerificationController::notice() — must resolve to something or
// EnsureEmailIsVerified crashes on a non-JSON request, same as 'login'.
Route::get('/email/verify', [EmailVerificationController::class, 'notice'])
    ->middleware('auth')
    ->name('verification.notice');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::patch('/user', [ProfileController::class, 'update']);
    Route::patch('/user/password', [ProfileController::class, 'updatePassword']);
    Route::post('/user/avatar', [AvatarController::class, 'store']);
    Route::delete('/user/avatar', [AvatarController::class, 'destroy']);
    Route::delete('/user', [ProfileController::class, 'destroy']);
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');
});
