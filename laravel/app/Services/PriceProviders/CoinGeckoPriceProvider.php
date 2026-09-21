<?php

namespace App\Services\PriceProviders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CoinGeckoPriceProvider implements AssetInfoProviderInterface, PercentChangeProviderInterface, PriceProviderInterface
{
    public function getUsdPrices(array $externalIds): array
    {
        return collect($this->getMarkets($externalIds))
            ->map(fn (array $coin) => $coin['current_price'] ?? null)
            ->toArray();
    }

    /**
     * The /coins/markets endpoint reports 1h/24h/7d change alongside the
     * price in the same payload, so this reuses the cached fetch from
     * getUsdPrices() instead of a second request.
     */
    public function getPercentChanges(array $externalIds): array
    {
        return collect($this->getMarkets($externalIds))
            ->map(fn (array $coin) => [
                '1h' => $coin['price_change_percentage_1h_in_currency'] ?? null,
                '24h' => $coin['price_change_percentage_24h_in_currency'] ?? null,
                '7d' => $coin['price_change_percentage_7d_in_currency'] ?? null,
            ])
            ->toArray();
    }

    /**
     * @param  array<int, string>  $externalIds
     * @return array<string, array<string, mixed>> Keyed by coin id.
     */
    private function getMarkets(array $externalIds): array
    {
        if (empty($externalIds)) {
            return [];
        }

        sort($externalIds);
        $cacheKey = 'crypto_markets_'.md5(implode(',', $externalIds));
        $backupKey = $cacheKey.'_backup';

        return Cache::remember($cacheKey, 45, function () use ($externalIds, $backupKey) {
            try {
                $response = Http::get('https://api.coingecko.com/api/v3/coins/markets', [
                    'vs_currency' => 'usd',
                    'ids' => implode(',', $externalIds),
                    'price_change_percentage' => '1h,24h,7d',
                ]);
                $response->throw();

                $markets = collect($response->json())->keyBy('id')->toArray();

                Cache::forever($backupKey, $markets);

                return $markets;
            } catch (\Exception $e) {
                if (Cache::has($backupKey)) {
                    return Cache::get($backupKey);
                }

                report($e);

                return [];
            }
        });
    }

    public function getInfo(string $externalId): array
    {
        $cacheKey = "coingecko_info_{$externalId}";
        $empty = ['description' => null, 'market_cap_usd' => null];

        return Cache::remember($cacheKey, 3600, function () use ($externalId, $empty) {
            try {
                $response = Http::get("https://api.coingecko.com/api/v3/coins/{$externalId}", [
                    'localization' => 'false',
                    'tickers' => 'false',
                    'community_data' => 'false',
                    'developer_data' => 'false',
                    'sparkline' => 'false',
                ]);
                $response->throw();
                $data = $response->json();

                return [
                    'description' => data_get($data, 'description.en') ?: null,
                    'market_cap_usd' => data_get($data, 'market_data.market_cap.usd'),
                ];
            } catch (\Exception $e) {
                report($e);

                return $empty;
            }
        });
    }
}
