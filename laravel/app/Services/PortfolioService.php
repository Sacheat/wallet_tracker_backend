<?php

namespace App\Services;

use App\DTO\PortfolioSummary;
use App\Models\PortfolioEntry;
use App\Models\User;

class PortfolioService
{
    /**
     * Beyond USD/EUR (already shown in the UI), currencies exposed via
     * total_value_by_currency for a future currency picker.
     */
    private const EXTRA_CURRENCIES = ['GBP', 'CHF'];

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
        $rates = $this->priceService->getUsdToCurrencyRates(['EUR', ...self::EXTRA_CURRENCIES]);
        $eurRate = $rates['EUR'];
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
        $totalValueUsd = round($sortedEntries->sum('value_usd'), 2);
        $totalValueEur = round($sortedEntries->sum('value_eur'), 2);

        $totalValueByCurrency = ['USD' => $totalValueUsd, 'EUR' => $totalValueEur];
        foreach (self::EXTRA_CURRENCIES as $currency) {
            $rate = $rates[$currency] ?? null;
            $totalValueByCurrency[$currency] = $rate !== null ? round($totalValueUsd * $rate, 2) : null;
        }

        return new PortfolioSummary(
            entries: $sortedEntries,
            totalValueUsd: $totalValueUsd,
            totalValueEur: $totalValueEur,
            changePercent: $changePercent,
            totalValueByCurrency: $totalValueByCurrency,
        );
    }
}
