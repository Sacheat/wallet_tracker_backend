<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PriceService
{
    public function getUsdToEurRate(): float {
        return Cache::remember('usd_eur_rate', 45, function () {
            try {
                $response = Http::get('https://api.frankfurter.dev/v2/rates', [
                    'base' => 'USD',
                    'symbols' => 'EUR',
                ]);

                $response->throw();
                $rate = $response->json()['rates']['EUR'];
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

    public function getCryptoUsdPrice(array $coinGeckoIds): array
    {
        sort($coinGeckoIds);
        $cacheKey = 'crypto_usd_prices_' . md5(implode(',', $coinGeckoIds));
        $backupKey = $cacheKey . '_backup';

        return Cache::remember($cacheKey, 45, function () use ($coinGeckoIds, $backupKey) {
            try {
                $response = Http::get('https://api.coingecko.com/api/v3/simple/price', [
                    'ids' => implode(',', $coinGeckoIds),
                    'vs_currencies' => 'usd',
                ]);
                $response->throw();

                $prices = $response->json();
                Cache::forever($backupKey, $prices);
                return $prices;

            } catch (\Exception $e) {
                if (Cache::has($backupKey)) {
                    return Cache::get($backupKey);
                }
                throw new \RuntimeException("Impossible de récupérer le prix", 0, $e);
            }
        });
    }
}
