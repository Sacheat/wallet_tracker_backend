<?php

namespace App\Services\PriceProviders;

/**
 * Optional capability: providers that can report their own 1h/24h/7d percent
 * change (CoinGecko, Yahoo Finance) implement this so PriceChangeCalculator
 * uses live provider data instead of waiting for local price_snapshots
 * history to accumulate.
 */
interface PercentChangeProviderInterface
{
    /**
     * @param  array<int, string>  $externalIds
     * @return array<string, array<string, float|null>> Keyed by external id, each value keyed by AlertWindow->value ('1h', '24h', '7d').
     */
    public function getPercentChanges(array $externalIds): array;
}
