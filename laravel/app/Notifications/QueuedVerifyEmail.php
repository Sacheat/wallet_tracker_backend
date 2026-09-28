<?php

namespace App\Notifications;

use App\Notifications\Concerns\QueuedWithRetries;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * Laravel's stock verification email, sent through the queue so
 * register/resend don't wait on the mail provider. Inherits toMail() and the
 * static URL/mail callbacks unchanged.
 */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use QueuedWithRetries;

    /**
     * In production the browser reaches this backend through the Vercel
     * proxy (https://<front>/backend/... → alwaysdata, prefix stripped), so
     * the host and path the user clicks differ from the ones the backend
     * sees. The signature therefore covers only the relative path + query
     * (validated by 'signed:relative' on the route), and the link is
     * prefixed with APP_URL — the proxied base the user can actually reach.
     */
    protected function verificationUrl($notifiable): string
    {
        $relativeUrl = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
            absolute: false,
        );

        return rtrim(Config::get('app.url'), '/').$relativeUrl;
    }
}
