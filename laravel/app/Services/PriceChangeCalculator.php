<?php

namespace App\Services;

use App\Enums\AlertWindow;
use App\Models\Asset;
use App\Models\PriceSnapshot;
use App\Services\PriceProviders\PercentChangeProviderInterface;

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

    private function percentChangeFromSnapshots(int $assetId, AlertWindow $window, float $currentPrice): ?float
    {
        $oldPrice = PriceSnapshot::priceAt($assetId, now()->subHours($window->hours()));

        if ($oldPrice === null || $oldPrice == 0.0) {
            return null;
        }

        return (($currentPrice - $oldPrice) / $oldPrice) * 100;
    }
}
