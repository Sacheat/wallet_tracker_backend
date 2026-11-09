<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Unsubscribe link of the alert and digest emails, also advertised in
 * their List-Unsubscribe header. Turns off notification emails only
 * (email_notifications_enabled): Discord and the account's security
 * emails keep going, and it can be switched back on from the account page.
 *
 * No login: the signed URL is the proof (no expiry, a client may use it
 * weeks later). GET only shows a confirmation, because mail scanners open
 * links on their own; the unsubscribe itself is a POST, either from that
 * page's button or straight from the mail client (RFC 8058 one-click,
 * hence the CSRF exemption in bootstrap/app.php). Plain HTML pages rather
 * than the app's JSON: a person lands here from their inbox.
 */
class EmailUnsubscribeController extends Controller
{
    public function show(Request $request, User $user): Response
    {
        if (! $request->hasValidRelativeSignature()) {
            return $this->page('invalid', $user, 403);
        }

        return $this->page($user->email_notifications_enabled ? 'confirm' : 'done', $user);
    }

    public function store(Request $request, User $user): Response
    {
        if (! $request->hasValidRelativeSignature()) {
            return $this->page('invalid', $user, 403);
        }

        if ($user->email_notifications_enabled) {
            $user->update(['email_notifications_enabled' => false]);
        }

        return $this->page('done', $user);
    }

    /**
     * @param  'confirm'|'done'|'invalid'  $state
     */
    private function page(string $state, User $user, int $status = 200): Response
    {
        return response()->view('unsubscribe', [
            'state' => $state,
            'email' => $user->email,
            // Rebuilt on APP_URL, the public base: behind the proxy the
            // path this backend sees lacks the prefix the browser needs
            // (same reason as QueuedVerifyEmail::verificationUrl()).
            'actionUrl' => rtrim(config('app.url'), '/').request()->getRequestUri(),
            'accountUrl' => config('mail.brand.frontend_url').'/account',
        ], $status);
    }
}
