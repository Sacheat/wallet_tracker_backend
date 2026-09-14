<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Services\PriceProviders\AlphaVantagePriceProvider;
use App\Services\PriceProviders\CoinGeckoPriceProvider;
use App\Services\PriceProviders\PriceProviderInterface;
use App\Services\PriceProviders\YahooFinanceGoldPriceProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PriceService
{
    /** @var array<string, PriceProviderInterface> */
    private array $providers;

    public function __construct(
        CoinGeckoPriceProvider $coinGeckoPriceProvider,
        AlphaVantagePriceProvider $alphaVantagePriceProvider,
        YahooFinanceGoldPriceProvider $yahooFinanceGoldPriceProvider,
    ) {
        $this->providers = [
            AssetType::Crypto->value => $coinGeckoPriceProvider,
            AssetType::Stock->value => $alphaVantagePriceProvider,
            AssetType::Etf->value => $alphaVantagePriceProvider,
            AssetType::Gold->value => $yahooFinanceGoldPriceProvider,
        ];
    }

    public function getUsdToEurRate(): float
    {
        return Cache::remember('usd_eur_rate', 45, function () {
            try {
                $response = Http::get('https://api.frankfurter.dev/v2/rates', [
                    'base' => 'USD',
                ]);

                $response->throw();
                $rate = collect($response->json())->firstWhere('quote', 'EUR')['rate'];
                Cache::forever('usd_eur_rate_backup', $rate);

                return $rate;

            } catch (\Exception $e) {
                if (Cache::has('usd_eur_rate_backup')) {
                    return Cache::get('usd_eur_rate_backup');
                }
                throw new \RuntimeException('Impossible de récupérer le taux USD/EUR', 0, $e);
            }
        });
    }

    /**
     * Resolve the current USD price for each asset, dispatching to the
     * provider registered for its type and merging the results.
     *
     * @param  Collection<int, Asset>  $assets
     * @return array<string, float|null> Keyed by the asset's external_id.
     */
    public function getUsdPrices(Collection $assets): array
    {
        $prices = [];

        foreach ($assets->groupBy(fn ($asset) => $asset->type->value) as $type => $assetsOfType) {
            $provider = $this->providers[$type] ?? null;
            $externalIds = $assetsOfType->pluck('external_id')->filter()->unique()->values()->toArray();

            if (! $provider || empty($externalIds)) {
                continue;
            }

            $prices += $provider->getUsdPrices($externalIds);
        }

        return $prices;
    }

    public function providerFor(AssetType $type): PriceProviderInterface
    {
        return $this->providers[$type->value];
    }
}
