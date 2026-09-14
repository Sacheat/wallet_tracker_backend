<?php

namespace App\Services\PriceProviders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AlphaVantagePriceProvider implements AssetInfoProviderInterface, PriceProviderInterface
{
    /**
     * Alpha Vantage's free tier has no batch quote endpoint (GLOBAL_QUOTE takes
     * one symbol at a time) and is capped at 25 requests/day, 5/minute. Each
     * symbol is cached individually and for longer than the crypto price to
     * stay well under that budget.
     */
    private const CACHE_TTL_SECONDS = 900;

    public function getUsdPrices(array $externalIds): array
    {
        $apiKey = config('services.alpha_vantage.key');

        if (empty($apiKey)) {
            Log::warning('Alpha Vantage API key missing; skipping stock/ETF price lookup.');

            return array_fill_keys($externalIds, null);
        }

        $prices = [];

        foreach ($externalIds as $symbol) {
            $prices[$symbol] = $this->getSinglePrice($symbol, $apiKey);
        }

        return $prices;
    }

    private function getSinglePrice(string $symbol, string $apiKey): ?float
    {
        $cacheKey = "alpha_vantage_usd_price_{$symbol}";
        $backupKey = "{$cacheKey}_backup";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($symbol, $apiKey, $backupKey) {
            try {
                $response = Http::get('https://www.alphavantage.co/query', [
                    'function' => 'GLOBAL_QUOTE',
                    'symbol' => $symbol,
                    'apikey' => $apiKey,
                ]);
                $response->throw();

                // Array form, not a dotted string: the real key "05. price"
                // contains a literal dot, which data_get's dot-notation would
                // otherwise misparse as a nested path.
                $price = (float) data_get($response->json(), ['Global Quote', '05. price']);

                if ($price <= 0) {
                    throw new \RuntimeException("Alpha Vantage returned no price for {$symbol}");
                }

                Cache::forever($backupKey, $price);

                return $price;
            } catch (\Exception $e) {
                if (Cache::has($backupKey)) {
                    return Cache::get($backupKey);
                }

                report($e);

                return null;
            }
        });
    }

    /**
     * Shares Alpha Vantage's 25 req/day free-tier budget with price
     * snapshots — cached for a full day to minimize the impact of viewing
     * an asset detail page on that already-tight quota.
     */
    public function getInfo(string $externalId): array
    {
        $apiKey = config('services.alpha_vantage.key');
        $empty = ['description' => null, 'market_cap_usd' => null];

        if (empty($apiKey)) {
            Log::warning('Alpha Vantage API key missing; skipping stock/ETF asset info lookup.');

            return $empty;
        }

        return Cache::remember("alpha_vantage_info_{$externalId}", 86400, function () use ($externalId, $apiKey, $empty) {
            try {
                $response = Http::get('https://www.alphavantage.co/query', [
                    'function' => 'OVERVIEW',
                    'symbol' => $externalId,
                    'apikey' => $apiKey,
                ]);
                $response->throw();
                $data = $response->json();

                return [
                    'description' => $data['Description'] ?? null,
                    'market_cap_usd' => isset($data['MarketCapitalization']) ? (float) $data['MarketCapitalization'] : null,
                ];
            } catch (\Exception $e) {
                report($e);

                return $empty;
            }
        });
    }
}
