<?php

namespace App\Services\PriceProviders;

interface PriceProviderInterface
{
    /**
     * Fetch the current USD price for each given external id.
     *
     * @param  array<int, string>  $externalIds
     * @return array<string, float|null> Keyed by external id; null when the price could not be resolved.
     */
    public function getUsdPrices(array $externalIds): array;
}
