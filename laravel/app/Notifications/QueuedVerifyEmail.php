<?php

namespace App\Notifications;

use App\Notifications\Concerns\QueuedWithRetries;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
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
     * Behind a reverse proxy the host, scheme or path prefix the user clicks
     * can differ from what the backend sees. The signature therefore covers
     * only the relative path + query (validated by 'signed:relative' on the
     * route), and the link is prefixed with APP_URL — the public base the
     * user can actually reach.
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

    /**
     * The stock wording, in French (the app's only language).
     *
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = Config::get('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Confirmez votre adresse e-mail')
            ->greeting('Bienvenue sur '.Config::get('mail.brand.name').' !')
            ->line('Il ne reste qu\'une étape : confirmez votre adresse e-mail pour accéder à votre portefeuille et recevoir vos alertes.')
            ->action('Confirmer mon adresse', $url)
            ->line("Ce lien est valable {$minutes} minutes.")
            ->line('Si vous n\'avez pas créé de compte, ignorez simplement cet e-mail.')
            ->markdown('notifications::email', ['preheader' => 'Confirmez votre adresse pour activer votre compte.']);
    }
}
