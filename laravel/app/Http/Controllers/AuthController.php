<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => Hash::make($request->validated('password')),
        ]);
        // create() only carries the attributes passed above — columns filled
        // in by a DB-level default (currency, email_notifications_enabled...)
        // are missing from this in-memory instance until reloaded from the
        // row Laravel just inserted, unlike /login's response (a fresh query).
        $user->refresh();

        Auth::login($user);
        $request->session()->regenerate();

        // Queued: a broken mail provider or a rejected recipient fails the
        // job (retried, then failed_jobs), never the registration itself.
        $user->sendEmailVerificationNotification();

        return response()->json($user, 201);
    }

    public function login(LoginRequest $request)
    {
        // 'remember' isn't a users column: Auth::attempt() would otherwise
        // try to filter WHERE remember = ? alongside email/password.
        $credentials = $request->safe()->only(['email', 'password']);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => ['Ces identifiants ne correspondent à aucun compte.'],
            ]);
        }

        $request->session()->regenerate();

        return response()->json($request->user());
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return $request->user();
    }
}
