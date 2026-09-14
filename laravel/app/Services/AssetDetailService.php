<?php

namespace App\Services;

use App\Enums\AlertWindow;
use App\Models\Asset;
use App\Models\PriceSnapshot;
use App\Services\PriceProviders\AssetInfoProviderInterface;
use Illuminate\Support\Collection;

class AssetDetailService
{
    public function __construct(
        private PriceService $priceService,
        private PriceChangeCalculator $priceChangeCalculator,
    ) {}

    /**
     * @return array{
     *     price_usd: ?float,
     *     description: ?string,
     *     market_cap_usd: ?float,
     *     change_percent: array<string, ?float>,
     *     history_7d: Collection<int, PriceSnapshot>,
     * }
     */
    public function getDetails(Asset $asset): array
    {
        $prices = $this->priceService->getUsdPrices(collect([$asset]));
        $currentPrice = $prices[$asset->external_id] ?? null;

        $info = ['description' => null, 'market_cap_usd' => null];
        $provider = $this->priceService->providerFor($asset->type);

        if ($provider instanceof AssetInfoProviderInterface && $asset->external_id) {
            $info = $provider->getInfo($asset->external_id);
        }

        $changePercent = [];
        foreach (AlertWindow::cases() as $window) {
            $changePercent[$window->value] = $currentPrice !== null
                ? $this->priceChangeCalculator->percentChange($asset->id, $window, $currentPrice)
                : null;
        }

        $history = PriceSnapshot::where('asset_id', $asset->id)
            ->where('recorded_at', '>=', now()->subDays(7))
            ->orderBy('recorded_at')
            ->get(['price_usd', 'recorded_at']);

        return [
            'price_usd' => $currentPrice,
            'description' => $info['description'],
            'market_cap_usd' => $info['market_cap_usd'],
            'change_percent' => $changePercent,
            'history_7d' => $history,
        ];
    }
}
