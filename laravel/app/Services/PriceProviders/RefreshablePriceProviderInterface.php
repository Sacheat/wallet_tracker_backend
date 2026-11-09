<?php

namespace App\Services\PriceProviders;

/**
 * Optional capability: fetch the current price (and the data behind the
 * 1h/24h/7d changes) of many assets in as few requests as the provider
 * allows, and store it in the same cache every regular read uses. Called
 * every five minutes by alerts:check (through PriceService::refreshPrices()),
 * so alerts, the portfolio page and the digest all read warm prices
 * instead of each one calling the provider.
 */
interface RefreshablePriceProviderInterface
{
    /**
     * Always fetches, even for ids already cached: the point is fresh
     * prices. On failure the provider keeps serving its last good copy.
     *
     * @param  array<int, string>  $externalIds
     */
    public function refresh(array $externalIds): void;
}
