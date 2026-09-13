<?php

namespace App\Services;

use App\DTO\PortfolioSummary;
use App\Models\PortfolioEntry;

class PortfolioService
{
    public function __construct(private PriceService $priceService)
    {
    }

    public function getPortfolioSummary(): PortfolioSummary
    {
        $entries = PortfolioEntry::with(['crypto', 'platform'])->get();
        $coingeckoIds = $entries->pluck('crypto.coingecko_id')->unique()->values()->toArray();
        $prices = $this->priceService->getCryptoUsdPrice($coingeckoIds);
        $eurRate = $this->priceService->getUsdToEurRate();

        $enrichedEntries = $entries->map(function (PortfolioEntry $entry) use ($prices, $eurRate) {
            $priceUsd = $prices[$entry->crypto->coingecko_id]['usd'] ?? 0;
            $valueUsd = $entry->quantity * $priceUsd;
            $valueEur = $valueUsd * $eurRate;

            $entry->value_usd = round($valueUsd, 2);
            $entry->value_eur = round($valueEur, 2);

            return $entry;
        });

        $sortedEntries = $enrichedEntries->sortByDesc('value_usd')->values();
        $totalValueUsd = $sortedEntries->sum('value_usd');
        $totalValueEur = $sortedEntries->sum('value_eur');

        return new PortfolioSummary(
            entries: $sortedEntries,
            totalValueUsd: round($totalValueUsd, 2),
            totalValueEur: round($totalValueEur, 2),
        );
    }
}
