<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Models\PriceAlert;
use App\Models\User;
use App\Notifications\PriceAlertTriggered;
use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use Tests\TestCase;

/**
 * Every email goes through the branded, French views: no stock English
 * wording left, and only notification emails offer to manage notifications.
 */
class MailBrandingTest extends TestCase
{
    private function user(): User
    {
        $user = new User(['name' => 'Sacha', 'email' => 'user@example.com']);
        $user->id = 1;

        return $user;
    }

    public function test_account_emails_are_in_french_with_the_brand(): void
    {
        foreach ([new QueuedVerifyEmail, new QueuedResetPassword('token')] as $notification) {
            $html = (string) $notification->toMail($this->user())->render();

            $this->assertStringContainsString('images/logo-email.png', $html);
            $this->assertStringContainsString("L'équipe Portfolio Tracker", html_entity_decode($html, ENT_QUOTES));
            $this->assertStringNotContainsString('Regards', $html);
            $this->assertStringNotContainsString('Hello!', $html);
            $this->assertStringNotContainsString('Gérer mes notifications', $html);
        }
    }

    public function test_the_verification_email_has_its_preheader_and_french_button(): void
    {
        $mail = (new QueuedVerifyEmail)->toMail($this->user());
        $html = html_entity_decode((string) $mail->render(), ENT_QUOTES);

        $this->assertSame('Confirmez votre adresse e-mail', $mail->subject);
        $this->assertStringContainsString('Confirmez votre adresse pour activer votre compte.', $html);
        $this->assertStringContainsString('Confirmer mon adresse', $html);
    }

    public function test_notification_emails_link_to_the_notification_settings(): void
    {
        $alert = new PriceAlert(['type' => AlertType::AllocationDrift, 'percent_threshold' => 5]);
        $notification = new PriceAlertTriggered($alert, 18.48, context: ['type' => 'crypto', 'percent' => 68.48, 'target_percent' => 50.0]);
        $html = (string) $notification->toMail($this->user())->render();

        $this->assertStringContainsString('Gérer mes notifications', $html);
        $this->assertStringContainsString(config('mail.brand.frontend_url').'/account', $html);
    }
}
