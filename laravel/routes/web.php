<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AvatarController;
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

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::patch('/user', [ProfileController::class, 'update']);
    Route::patch('/user/password', [ProfileController::class, 'updatePassword']);
    Route::post('/user/avatar', [AvatarController::class, 'store']);
    Route::delete('/user/avatar', [AvatarController::class, 'destroy']);
    Route::delete('/user', [ProfileController::class, 'destroy']);
});
