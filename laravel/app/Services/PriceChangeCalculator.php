<?php

namespace App\Services;

use App\Enums\AlertWindow;
use App\Models\Asset;
use App\Models\PortfolioEntry;
use App\Models\PriceSnapshot;
use App\Services\PriceProviders\PercentChangeProviderInterface;
use Illuminate\Support\Collection;

/**
 * Shared by alert evaluation (step 3) and the asset detail page (step 4) so
 * both report the same % change for the same window.
 *
 * Prefers the provider's own 1h/24h/7d figures (CoinGecko, Yahoo Finance)
 * since they reflect the real market immediately. Falls back to our local
 * price_snapshots history only if a provider doesn't expose native change
 * data or the live lookup fails.
 */
class PriceChangeCalculator
{
    public function __construct(private PriceService $priceService) {}

    public function percentChange(Asset $asset, AlertWindow $window, float $currentPrice): ?float
    {
        if (! $asset->external_id) {
            return null;
        }

        $provider = $this->priceService->providerFor($asset->type);

        if ($provider instanceof PercentChangeProviderInterface) {
            $change = $provider->getPercentChanges([$asset->external_id])[$asset->external_id][$window->value] ?? null;

            if ($change !== null) {
                return $change;
            }
        }

        return $this->percentChangeFromSnapshots($asset->id, $window, $currentPrice);
    }

    /**
     * Percent change of a whole portfolio's value, per window. Reconstructs
     * each entry's value at the start of the window from its own percent
     * change, then compares the summed value then vs. now — not a weighted
     * average of the per-asset percentages, which would understate the
     * result whenever assets move in different directions.
     *
     * @param  Collection<int, PortfolioEntry>  $entries
     * @param  array<string, float|null>  $prices  Current USD price keyed by asset external_id.
     * @return array<string, float|null> Keyed by AlertWindow->value.
     */
    public function portfolioPercentChanges(Collection $entries, array $prices): array
    {
        $result = [];

        foreach (AlertWindow::cases() as $window) {
            $currentTotal = 0.0;
            $oldTotal = 0.0;

            foreach ($entries as $entry) {
                $currentPrice = $prices[$entry->asset->external_id] ?? null;

                if ($currentPrice === null) {
                    continue;
                }

                $change = $this->percentChange($entry->asset, $window, $currentPrice);

                // -100% (or worse, which shouldn't happen for a real price)
                // would divide by zero or flip the sign when reconstructing
                // the old price — skip rather than corrupt the total.
                if ($change === null || $change <= -100.0) {
                    continue;
                }

                $oldPrice = $currentPrice / (1 + $change / 100);
                $currentTotal += $entry->quantity * $currentPrice;
                $oldTotal += $entry->quantity * $oldPrice;
            }

            $result[$window->value] = $oldTotal > 0.0
                ? (($currentTotal - $oldTotal) / $oldTotal) * 100
                : null;
        }

        return $result;
    }

    private function percentChangeFromSnapshots(int $assetId, AlertWindow $window, float $currentPrice): ?float
    {
        $oldPrice = PriceSnapshot::priceAt($assetId, now()->subHours($window->hours()));

        if ($oldPrice === null || $oldPrice == 0.0) {
            return null;
        }

        return (($currentPrice - $oldPrice) / $oldPrice) * 100;
    }
}
