<?php

namespace App\Services\PriceProviders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * No free, keyless "official" gold price API was found (metals-api.com has
 * no free plan, Alpha Vantage's CURRENCY_EXCHANGE_RATE rejects XAU, and
 * exchangerate.host now requires a key too — verified live, not assumed).
 * Yahoo Finance's unofficial chart endpoint is the fallback: works without
 * a key, but is undocumented and can change or break without notice.
 */
class YahooFinanceGoldPriceProvider implements PriceProviderInterface
{
    public function getUsdPrices(array $externalIds): array
    {
        $prices = [];

        foreach ($externalIds as $symbol) {
            $prices[$symbol] = $this->getSinglePrice($symbol);
        }

        return $prices;
    }

    private function getSinglePrice(string $symbol): ?float
    {
        $cacheKey = "yahoo_finance_usd_price_{$symbol}";
        $backupKey = "{$cacheKey}_backup";

        return Cache::remember($cacheKey, 900, function () use ($symbol, $backupKey) {
            try {
                $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get("https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}", [
                        'interval' => '1d',
                        'range' => '1d',
                    ]);
                $response->throw();

                $price = data_get($response->json(), 'chart.result.0.meta.regularMarketPrice');

                if (! $price) {
                    throw new \RuntimeException("Yahoo Finance returned no price for {$symbol}");
                }

                Cache::forever($backupKey, $price);

                return (float) $price;
            } catch (\Exception $e) {
                if (Cache::has($backupKey)) {
                    return Cache::get($backupKey);
                }

                report($e);

                return null;
            }
        });
    }
}
