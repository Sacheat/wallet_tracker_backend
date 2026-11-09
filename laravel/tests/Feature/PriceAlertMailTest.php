<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Enums\AlertWindow;
use App\Enums\Currency;
use App\Models\Asset;
use App\Models\PriceAlert;
use App\Models\User;
use App\Notifications\PriceAlertTriggered;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Content of the alert email per type: amounts in the user's currency,
 * links into the app, and catalog names unable to inject Markdown links.
 * In-memory models only (no DB here, see ExchangeRatesTest).
 */
class PriceAlertMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function user(Currency $currency = Currency::Usd): User
    {
        $user = new User(['name' => 'Sacha', 'email' => 'user@example.com']);
        $user->id = 1;
        $user->currency = $currency;

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assetAlert(array $attributes, string $assetName = 'Bitcoin'): PriceAlert
    {
        $asset = new Asset(['name' => $assetName, 'symbol' => 'BTC']);
        $asset->id = 7;
        $alert = new PriceAlert($attributes);
        $alert->asset_id = 7;
        $alert->setRelation('asset', $asset);

        return $alert;
    }

    private function html(PriceAlertTriggered $notification, User $user): string
    {
        return html_entity_decode((string) $notification->toMail($user)->render(), ENT_QUOTES);
    }

    public function test_a_price_alert_shows_price_threshold_and_a_link_to_the_asset(): void
    {
        $notification = new PriceAlertTriggered($this->assetAlert(['type' => AlertType::PriceAbove, 'threshold' => 100000]), 104250.37);
        $mail = $notification->toMail($this->user());
        $html = $this->html($notification, $this->user());

        $this->assertSame('Bitcoin a dépassé 100 000,00 $', $mail->subject);
        $this->assertStringContainsString('104 250,37 $', $html);
        $this->assertStringContainsString('+4,25 %', $html);
        $this->assertStringContainsString(config('mail.brand.frontend_url').'/assets/7', $html);
    }

    public function test_amounts_are_converted_to_the_users_currency(): void
    {
        Cache::put('usd_exchange_rates', ['rates' => ['EUR' => 0.9], 'updated_at' => now()->toIso8601String()]);
        $notification = new PriceAlertTriggered(new PriceAlert(['type' => AlertType::PortfolioTotalBelow, 'threshold' => 20000]), 19000);

        $this->assertSame('Votre portefeuille est passé sous 18 000,00 €', $notification->toMail($this->user(Currency::Eur))->subject);
        $this->assertStringContainsString('17 100,00 €', $this->html($notification, $this->user(Currency::Eur)));
        $this->assertStringContainsString('(actuellement 17 100,00 €)', $notification->toDiscord($this->user(Currency::Eur)));
    }

    public function test_amounts_stay_in_usd_when_no_exchange_rate_is_available(): void
    {
        Http::fake(['api.frankfurter.dev/*' => Http::response(null, 500)]);
        $notification = new PriceAlertTriggered(new PriceAlert(['type' => AlertType::PortfolioTotalAbove, 'threshold' => 20000]), 21000);

        $this->assertSame('Votre portefeuille a dépassé 20 000,00 $', $notification->toMail($this->user(Currency::Eur))->subject);
    }

    public function test_a_percent_change_alert_names_its_window_and_cooldown(): void
    {
        $alert = $this->assetAlert(['type' => AlertType::PercentChange, 'percent_threshold' => 5, 'window' => AlertWindow::OneHour]);
        $notification = new PriceAlertTriggered($alert, 98540.12, -6.42);
        $html = $this->html($notification, $this->user());

        $this->assertSame('Bitcoin : -6,42 % en 1 h', $notification->toMail($this->user())->subject);
        $this->assertStringContainsString('Bitcoin recule de 6,42 %', $html);
        $this->assertStringContainsString("dans l'heure qui vient", $html);
    }

    public function test_a_drift_alert_shows_every_type_against_its_target(): void
    {
        $notification = new PriceAlertTriggered(new PriceAlert(['type' => AlertType::AllocationDrift, 'percent_threshold' => 5]), 18.48, context: [
            'type' => 'crypto', 'percent' => 68.48, 'target_percent' => 50.0,
            'rows' => [
                ['type' => 'crypto', 'percent' => 68.48, 'target_percent' => 50.0],
                ['type' => 'etf', 'percent' => 0.0, 'target_percent' => 15.0],
            ],
        ]);
        $html = $this->html($notification, $this->user());

        $this->assertStringContainsString('+18,5 pts', $html);
        $this->assertStringContainsString('-15 pts', $html);
        $this->assertStringContainsString('objectif 15 %', $html);
        $this->assertStringContainsString(config('mail.brand.frontend_url').'/allocation', $html);
    }

    public function test_a_catalog_name_cannot_inject_a_link(): void
    {
        $alert = $this->assetAlert(['type' => AlertType::PriceBelow, 'threshold' => 10], 'Gratuit [Réclamez ici](https://evil.example)');
        $notification = new PriceAlertTriggered($alert, 5);

        $this->assertStringNotContainsString('href="https://evil.example"', $this->html($notification, $this->user()));
    }
}
