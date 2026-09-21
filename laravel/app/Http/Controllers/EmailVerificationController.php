<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Signed link from the verification email. Not JSON: the browser
     * navigates here directly, so it ends in a redirect back to the SPA
     * rather than a response the frontend would have to parse.
     */
    public function verify(EmailVerificationRequest $request)
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->fulfill();
        }

        $frontendUrl = rtrim(config('cors.allowed_origins')[0] ?? 'http://localhost:5173', '/');

        return redirect("{$frontendUrl}/?verified=1");
    }

    public function resend(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email déjà vérifié.']);
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => "Impossible d'envoyer l'email pour le moment."], 503);
        }

        return response()->json(['message' => 'Email de vérification envoyé.']);
    }

    /**
     * Named 'verification.notice': EnsureEmailIsVerified redirects here for
     * a non-JSON request without a verified email. The SPA never triggers
     * this path (it always sends Accept: application/json), but the route
     * must resolve to something or the middleware crashes trying to build
     * the redirect URL — same reasoning as the named 'login' route.
     */
    public function notice()
    {
        return response()->json(['message' => 'Email non vérifié.'], 403);
    }
}
