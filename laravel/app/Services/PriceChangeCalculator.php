<?php

namespace App\Services;

use App\Enums\AlertWindow;
use App\Models\PriceSnapshot;

/**
 * Shared by alert evaluation (step 3) and the asset detail page (step 4) so
 * both report the same % change for the same window, computed from the same
 * price_snapshots history.
 */
class PriceChangeCalculator
{
    public function percentChange(int $assetId, AlertWindow $window, float $currentPrice): ?float
    {
        $oldPrice = PriceSnapshot::priceAt($assetId, now()->subHours($window->hours()));

        if ($oldPrice === null || $oldPrice == 0.0) {
            return null;
        }

        return (($currentPrice - $oldPrice) / $oldPrice) * 100;
    }
}
