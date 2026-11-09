<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Models\PriceAlert;
use App\Models\User;
use App\Notifications\PriceAlertTriggered;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * List-Unsubscribe headers and footer link of notification emails. The
 * controller behind the link needs the DB (route model binding) and was
 * checked live; this covers what the email carries.
 */
class EmailUnsubscribeTest extends TestCase
{
    private function user(): User
    {
        $user = new User(['name' => 'Sacha', 'email' => 'user@example.com']);
        $user->id = 42;

        return $user;
    }

    private function alertMail(): MailMessage
    {
        $alert = new PriceAlert(['type' => AlertType::PortfolioTotalAbove, 'threshold' => 1000]);

        return (new PriceAlertTriggered($alert, 1200))->toMail($this->user());
    }

    /**
     * @return array<string, string>
     */
    private function headers(MailMessage $mail): array
    {
        $email = new Email;
        foreach ($mail->callbacks as $callback) {
            $callback($email);
        }

        return collect($email->getHeaders()->all())
            ->mapWithKeys(fn ($header) => [$header->getName() => $header->getBodyAsString()])
            ->all();
    }

    private function unsubscribeUrl(MailMessage $mail): string
    {
        return trim($this->headers($mail)['List-Unsubscribe'], '<>');
    }

    public function test_notification_emails_carry_one_click_unsubscribe_headers(): void
    {
        $headers = $this->headers($this->alertMail());

        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
        $this->assertStringStartsWith('<'.rtrim(config('app.url'), '/').'/email/unsubscribe/42?signature=', $headers['List-Unsubscribe']);
    }

    public function test_the_link_is_signed_and_a_tampered_one_is_not(): void
    {
        $relative = substr($this->unsubscribeUrl($this->alertMail()), strlen(rtrim(config('app.url'), '/')));

        $this->assertTrue(Request::create($relative)->hasValidRelativeSignature());
        $this->assertFalse(Request::create(str_replace('/unsubscribe/42', '/unsubscribe/43', $relative))->hasValidRelativeSignature());
    }

    public function test_the_footer_offers_the_same_link(): void
    {
        $mail = $this->alertMail();
        $html = html_entity_decode((string) $mail->render(), ENT_QUOTES);

        $this->assertStringContainsString('Se désabonner', $html);
        $this->assertStringContainsString($this->unsubscribeUrl($mail), $html);
    }

    public function test_account_emails_have_no_unsubscribe(): void
    {
        $mail = (new QueuedVerifyEmail)->toMail($this->user());

        $this->assertArrayNotHasKey('List-Unsubscribe', $this->headers($mail));
        $this->assertStringNotContainsString('Se désabonner', (string) $mail->render());
    }
}
