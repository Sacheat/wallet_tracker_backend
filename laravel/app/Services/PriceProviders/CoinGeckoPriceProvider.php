<?php

namespace App\Services\PriceProviders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CoinGeckoPriceProvider implements AssetInfoProviderInterface, PriceProviderInterface
{
    public function getUsdPrices(array $externalIds): array
    {
        if (empty($externalIds)) {
            return [];
        }

        sort($externalIds);
        $cacheKey = 'crypto_usd_prices_'.md5(implode(',', $externalIds));
        $backupKey = $cacheKey.'_backup';

        return Cache::remember($cacheKey, 45, function () use ($externalIds, $backupKey) {
            try {
                $response = Http::get('https://api.coingecko.com/api/v3/simple/price', [
                    'ids' => implode(',', $externalIds),
                    'vs_currencies' => 'usd',
                ]);
                $response->throw();

                $prices = collect($response->json())
                    ->map(fn (array $price) => $price['usd'] ?? null)
                    ->toArray();

                Cache::forever($backupKey, $prices);

                return $prices;
            } catch (\Exception $e) {
                if (Cache::has($backupKey)) {
                    return Cache::get($backupKey);
                }

                report($e);

                return array_fill_keys($externalIds, null);
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
