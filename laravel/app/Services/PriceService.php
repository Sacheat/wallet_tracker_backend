<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Services\PriceProviders\CoinGeckoPriceProvider;
use App\Services\PriceProviders\PriceProviderInterface;
use App\Services\PriceProviders\YahooFinancePriceProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PriceService
{
    /** @var array<string, PriceProviderInterface> */
    private array $providers;

    public function __construct(
        CoinGeckoPriceProvider $coinGeckoPriceProvider,
        YahooFinancePriceProvider $yahooFinancePriceProvider,
    ) {
        $this->providers = [
            AssetType::Crypto->value => $coinGeckoPriceProvider,
            AssetType::Stock->value => $yahooFinancePriceProvider,
            AssetType::Etf->value => $yahooFinancePriceProvider,
            AssetType::Gold->value => $yahooFinancePriceProvider,
        ];
    }

    public function getUsdToEurRate(): float
    {
        return $this->getUsdToCurrencyRates(['EUR'])['EUR'];
    }

    /**
     * Frankfurter's /v2/rates has no filter param (a "symbols" query string
     * returns a 422 — confirmed live, not assumed): it always returns every
     * currency, so this fetches once and picks out whichever are asked for.
     *
     * @param  array<int, string>  $currencies  ISO codes, e.g. ['EUR', 'GBP', 'CHF'].
     * @return array<string, float|null> Keyed by currency; null if that currency wasn't in the response.
     */
    public function getUsdToCurrencyRates(array $currencies): array
    {
        $rates = Cache::remember('usd_exchange_rates', 45, function () {
            try {
                $response = Http::get('https://api.frankfurter.dev/v2/rates', [
                    'base' => 'USD',
                ]);

                $response->throw();
                $rates = collect($response->json())->pluck('rate', 'quote')->toArray();
                Cache::forever('usd_exchange_rates_backup', $rates);

                return $rates;

            } catch (\Exception $e) {
                if (Cache::has('usd_exchange_rates_backup')) {
                    return Cache::get('usd_exchange_rates_backup');
                }
                throw new \RuntimeException('Impossible de récupérer les taux de change USD', 0, $e);
            }
        });

        return collect($currencies)
            ->mapWithKeys(fn (string $currency) => [$currency => $rates[$currency] ?? null])
            ->all();
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
