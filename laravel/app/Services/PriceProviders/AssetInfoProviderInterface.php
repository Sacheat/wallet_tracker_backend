<?php

namespace App\Services\PriceProviders;

/**
 * Optional capability: only providers that expose a "profile" (description,
 * market cap) implement this — YahooFinancePriceProvider doesn't. Its
 * profile endpoint (quoteSummary) requires a session crumb Yahoo's chart
 * endpoint doesn't, so stock/ETF/gold asset details simply have no
 * description or market cap (confirmed live, not assumed).
 */
interface AssetInfoProviderInterface
{
    /**
     * @return array{description: ?string, market_cap_usd: ?float}
     */
    public function getInfo(string $externalId): array;
}
