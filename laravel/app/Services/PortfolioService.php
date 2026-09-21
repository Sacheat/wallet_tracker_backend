<?php

namespace App\Services;

use App\DTO\PortfolioSummary;
use App\Models\PortfolioEntry;
use App\Models\User;

class PortfolioService
{
    public function __construct(
        private PriceService $priceService,
        private PriceChangeCalculator $priceChangeCalculator,
    ) {}

    public function getPortfolioSummary(User $user): PortfolioSummary
    {
        $entries = PortfolioEntry::with(['asset', 'platform'])
            ->where('user_id', $user->id)
            ->get();
        $prices = $this->priceService->getUsdPrices($entries->pluck('asset'));
        $eurRate = $this->priceService->getUsdToEurRate();
        $changePercent = $this->priceChangeCalculator->portfolioPercentChanges($entries, $prices);

        $enrichedEntries = $entries->map(function (PortfolioEntry $entry) use ($prices, $eurRate) {
            $priceUsd = $prices[$entry->asset->external_id] ?? 0;
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
            changePercent: $changePercent,
        );
    }
}
