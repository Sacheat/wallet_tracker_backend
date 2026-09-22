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
     * @param  string  $locale  ISO 639-1-ish code (e.g. 'fr', 'en'). Falls
     *                          back to English when the provider has no
     *                          description in the requested language.
     * @return array{description: ?string, market_cap_usd: ?float}
     */
    public function getInfo(string $externalId, string $locale = 'en'): array;
}
