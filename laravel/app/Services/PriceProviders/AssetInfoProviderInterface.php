<?php

namespace App\Services\PriceProviders;

/**
 * Optional capability: only providers that expose a "profile" (description,
 * market cap) implement this — YahooFinanceGoldPriceProvider doesn't, since a
 * commodity has no company/project profile to describe.
 */
interface AssetInfoProviderInterface
{
    /**
     * @return array{description: ?string, market_cap_usd: ?float}
     */
    public function getInfo(string $externalId): array;
}
