<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;

/**
 * Notification emails (alerts, digests) carry an unsubscribe link: in the
 * footer, and in the List-Unsubscribe headers mail clients turn into their
 * own "Unsubscribe" button. Gmail and Yahoo expect both headers from
 * senders of recurring email, and a visible way out keeps people from
 * reporting the emails as spam instead.
 */
trait OffersUnsubscribe
{
    /**
     * Signed without expiry and relative, then prefixed with APP_URL: the
     * public base, as for the verification link (the proxy strips its
     * prefix before the request reaches this backend).
     */
    protected function unsubscribeUrl(object $notifiable): string
    {
        $relativeUrl = URL::signedRoute('email.unsubscribe', ['user' => $notifiable->getKey()], absolute: false);

        return rtrim(config('app.url'), '/').$relativeUrl;
    }

    protected function withUnsubscribeHeaders(MailMessage $mail, string $unsubscribeUrl): MailMessage
    {
        return $mail->withSymfonyMessage(function (Email $message) use ($unsubscribeUrl) {
            $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$unsubscribeUrl}>");
            $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });
    }
}
