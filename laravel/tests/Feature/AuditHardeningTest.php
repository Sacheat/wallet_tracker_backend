<?php

namespace Tests\Feature;

use App\Enums\AlertType;
use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateNotificationSettingsRequest;
use App\Http\Requests\UpdatePortfolioEntryRequest;
use App\Models\PriceAlert;
use App\Models\User;
use App\Notifications\PriceAlertTriggered;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Fixes from the 2026-09-28 security audit that can be checked without a
 * database (see ExchangeRatesTest). The rest was verified live.
 */
class AuditHardeningTest extends TestCase
{
    private function notifiable(bool $verified): User
    {
        $user = new User(['email' => 'x@example.com', 'email_notifications_enabled' => true]);
        $user->email_verified_at = $verified ? now() : null;

        return $user;
    }

    public function test_notifications_are_not_mailed_to_an_unverified_address(): void
    {
        $notification = new PriceAlertTriggered(new PriceAlert(['type' => AlertType::PriceAbove]), 1.0);

        $this->assertSame([], $notification->via($this->notifiable(verified: false)));
        $this->assertSame(['mail'], $notification->via($this->notifiable(verified: true)));
    }

    public function test_external_id_is_limited_to_provider_identifier_characters(): void
    {
        $rule = fn (string $id) => Validator::make(['external_id' => $id], ['external_id' => array_filter(
            (new StoreAssetRequest)->rules()['external_id'],
            fn ($r) => ! str_starts_with($r, 'unique'),
        )])->passes();

        foreach (['bitcoin', 'usd-coin', 'GC=F', '^GSPC', 'BRK-B', '0700.HK'] as $valid) {
            $this->assertTrue($rule($valid), $valid);
        }
        foreach (['../../v1/finance/search', 'AAPL?x=1', 'a b', 'a/b', 'a#b'] as $invalid) {
            $this->assertFalse($rule($invalid), $invalid);
        }
    }

    public function test_numeric_inputs_stay_within_their_column(): void
    {
        $quantity = (new UpdatePortfolioEntryRequest)->rules()['quantity'];
        $this->assertTrue(Validator::make(['q' => '1e15'], ['q' => $quantity])->fails());
        $this->assertTrue(Validator::make(['q' => '123456789.12345678'], ['q' => $quantity])->passes());

        $discord = (new UpdateNotificationSettingsRequest)->rules()['discord_webhook_url'];
        $this->assertTrue(Validator::make(['u' => 'https://discord.com/api/webhooks/'.str_repeat('a', 300)], ['u' => $discord])->fails());
    }
}
