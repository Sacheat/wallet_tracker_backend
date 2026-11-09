<?php

namespace App\Notifications;

use App\Notifications\Concerns\QueuedWithRetries;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Laravel's stock reset-link email, sent through the queue. Also shrinks the
 * timing gap /forgot-password had between a known and an unknown address
 * (only the former used to wait on the mail provider); a smaller one remains
 * because the broker still hashes a token for a known account.
 * ResetPassword::createUrlUsing() in AppServiceProvider is a static on the
 * parent class, so the SPA reset link keeps working unchanged.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use QueuedWithRetries;

    /**
     * The stock wording, in French (the app's only language).
     *
     * @param  string  $url
     */
    protected function buildMailMessage($url): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Réinitialisez votre mot de passe')
            ->line('Vous avez demandé à réinitialiser le mot de passe de votre compte.')
            ->action('Choisir un nouveau mot de passe', $url)
            ->line("Ce lien est valable {$minutes} minutes.")
            ->line('Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet e-mail : votre mot de passe reste inchangé.')
            ->markdown('notifications::email', ['preheader' => 'Lien de réinitialisation de votre mot de passe.']);
    }
}
