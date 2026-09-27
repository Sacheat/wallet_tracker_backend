<?php

namespace App\Services\PriceProviders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * No free, keyless "official" API was found for gold (metals-api.com has no
 * free plan, Alpha Vantage's CURRENCY_EXCHANGE_RATE rejects XAU) and Alpha
 * Vantage's stock quotes are capped at 25 requests/day free — too tight to
 * fetch live 1h/24h/7d change on every page view. Yahoo Finance's unofficial
 * chart endpoint covers both: no key, no documented rate limit, and hourly
 * candles let price + all three change windows come from one request. It's
 * undocumented and can change or break without notice — confirmed live, not
 * assumed.
 */
class YahooFinancePriceProvider implements PercentChangeProviderInterface, PriceProviderInterface
{
    public function getUsdPrices(array $externalIds): array
    {
        $prices = [];

        foreach ($externalIds as $symbol) {
            $chart = $this->getChart($symbol);
            $prices[$symbol] = $chart['price'] ?? null;
        }

        return $prices;
    }

    public function getPercentChanges(array $externalIds): array
    {
        $changes = [];

        foreach ($externalIds as $symbol) {
            $chart = $this->getChart($symbol);

            if ($chart === null) {
                continue;
            }

            $changes[$symbol] = [
                '1h' => $this->percentChangeSince($chart, 1),
                '24h' => $this->percentChangeSince($chart, 24),
                '7d' => $this->percentChangeSince($chart, 24 * 7),
            ];
        }

        return $changes;
    }

    /**
     * @param  array{price: float, timestamps: array<int, int>, closes: array<int, ?float>}  $chart
     */
    private function percentChangeSince(array $chart, int $hours): ?float
    {
        $target = now()->subHours($hours)->timestamp;
        $oldPrice = null;

        foreach ($chart['timestamps'] as $index => $timestamp) {
            if ($timestamp > $target) {
                break;
            }

            $close = $chart['closes'][$index] ?? null;

            if ($close !== null) {
                $oldPrice = $close;
            }
        }

        if ($oldPrice === null || $oldPrice == 0.0) {
            return null;
        }

        return (($chart['price'] - $oldPrice) / $oldPrice) * 100;
    }

    /**
     * Hourly candles over 8 days comfortably cover the 1h/24h/7d windows in
     * a single request. Cached like the price alone was before, so this
     * doesn't add extra calls compared to the old gold-only provider.
     *
     * @return array{price: float, timestamps: array<int, int>, closes: array<int, ?float>}|null
     */
    private function getChart(string $symbol): ?array
    {
        $cacheKey = "yahoo_finance_chart_{$symbol}";
        $backupKey = "{$cacheKey}_backup";

        // false = cached "no price for this symbol". Cache::remember() treats
        // a stored null as a miss (see GeminiSummaryService), so returning
        // null here re-queried Yahoo on every read: a portfolio full of bogus
        // tickers turned each GET /api/portfolio into one outbound call per
        // ticker — enough to get this server's IP throttled by Yahoo, and
        // stock/ETF/gold prices with it, for every user.
        $chart = Cache::remember($cacheKey, 900, function () use ($symbol, $backupKey) {
            try {
                // rawurlencode: external_id is user-supplied and lands in the
                // path — without it, '../' or '?' could reach other Yahoo
                // endpoints. Checked live: Yahoo accepts GC%3DF, %5EGSPC.
                $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                    ->get('https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol), [
                        'interval' => '60m',
                        'range' => '8d',
                    ]);
                $response->throw();

                $result = data_get($response->json(), 'chart.result.0');
                $price = data_get($result, 'meta.regularMarketPrice');

                if (! $price) {
                    throw new \RuntimeException("Yahoo Finance returned no price for {$symbol}");
                }

                $chart = [
                    'price' => (float) $price,
                    'timestamps' => data_get($result, 'timestamp', []),
                    'closes' => data_get($result, 'indicators.quote.0.close', []),
                ];

                Cache::forever($backupKey, $chart);

                return $chart;
            } catch (\Exception $e) {
                if (Cache::has($backupKey)) {
                    return Cache::get($backupKey);
                }

                report($e);

                return false;
            }
        });

        return $chart ?: null;
    }
}
