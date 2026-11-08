<?php

namespace App\Services\PriceProviders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CoinGeckoPriceProvider implements AssetInfoProviderInterface, PercentChangeProviderInterface, PriceHistoryProviderInterface, PriceProviderInterface
{
    private const MARKET_TTL_SECONDS = 45;

    /** Documented maximum for /coins/markets per_page. */
    private const MAX_IDS_PER_REQUEST = 250;

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
     * Cached per coin, not per set of ids: any caller — the whole portfolio,
     * a single alert's asset, the asset detail page — reads the same entries
     * and only the coins missing from the cache are fetched, in one batched
     * request. (Keyed by the id set, a batched price lookup and the
     * per-asset change lookups that followed it never shared a cache entry,
     * so N coins cost N+1 requests, on a free tier that answers 429.)
     *
     * @param  array<int, string>  $externalIds
     * @return array<string, array<string, mixed>> Keyed by coin id; coins that couldn't be resolved are left out.
     */
    private function getMarkets(array $externalIds): array
    {
        $externalIds = array_values(array_unique($externalIds));

        if (empty($externalIds)) {
            return [];
        }

        $cached = Cache::many(array_map(fn (string $id) => $this->marketKey($id), $externalIds));
        $markets = [];
        $missing = [];

        foreach ($externalIds as $id) {
            $entry = $cached[$this->marketKey($id)] ?? null;

            if ($entry === null) {
                $missing[] = $id;
            } else {
                $markets[$id] = $entry;
            }
        }

        if ($missing) {
            $markets += $this->fetchMarkets($missing);
        }

        // An empty entry is a cached "couldn't resolve this coin".
        return array_filter($markets);
    }

    /**
     * One request per chunk of MAX_IDS_PER_REQUEST: /coins/markets paginates
     * (100 per page by default) and silently drops the rest — confirmed
     * live, not assumed.
     *
     * A failed request falls back to each coin's last good copy and, either
     * way, caches the outcome for the normal TTL so a rate-limited API isn't
     * hit again on every call.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array<string, mixed>>
     */
    private function fetchMarkets(array $ids): array
    {
        $fetched = [];

        foreach (array_chunk($ids, self::MAX_IDS_PER_REQUEST) as $chunk) {
            $rows = null;

            try {
                $response = Http::coingecko()->get('coins/markets', [
                    'vs_currency' => 'usd',
                    'ids' => implode(',', $chunk),
                    'per_page' => self::MAX_IDS_PER_REQUEST,
                    'price_change_percentage' => '1h,24h,7d',
                ]);
                $response->throw();

                $rows = collect($response->json())->keyBy('id')->toArray();
            } catch (\Exception $e) {
                report($e);
            }

            foreach ($chunk as $id) {
                $key = $this->marketKey($id);

                if (isset($rows[$id])) {
                    $entry = $rows[$id];
                    Cache::forever("{$key}_backup", $entry);
                } else {
                    // Request failed: last good copy, if any. Request fine
                    // but the coin absent (unknown id): nothing to serve.
                    $entry = $rows === null ? Cache::get("{$key}_backup", []) : [];
                }

                Cache::put($key, $entry, self::MARKET_TTL_SECONDS);
                $fetched[$id] = $entry;
            }
        }

        return $fetched;
    }

    /**
     * One request per coin for the whole year, cached an hour: every range
     * the history endpoint offers is a slice of it. CoinGecko's daily points
     * sit at 00:00 UTC (that day's opening price, i.e. the previous close);
     * the last point is the current price, so today is filled in too. A
     * failure serves the last good copy rather than a hole in the chart.
     */
    public function getDailyUsdCloses(string $externalId): array
    {
        $cacheKey = "coingecko_history_{$externalId}";
        $backupKey = "{$cacheKey}_backup";

        // false = cached "nothing available" (Cache::remember() treats a
        // stored null as a miss and would refetch on every read).
        $closes = Cache::remember($cacheKey, 3600, function () use ($externalId, $backupKey) {
            try {
                // external_id is user-supplied and lands in the path.
                $response = Http::coingecko()->get('coins/'.rawurlencode($externalId).'/market_chart', [
                    'vs_currency' => 'usd',
                    'days' => self::HISTORY_DAYS,
                    'interval' => 'daily',
                ])->throw();

                $closes = collect($response->json('prices', []))
                    ->filter(fn ($point) => is_array($point) && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null))
                    ->mapWithKeys(fn (array $point) => [gmdate('Y-m-d', intdiv((int) $point[0], 1000)) => (float) $point[1]])
                    ->all();

                Cache::forever($backupKey, $closes);

                return $closes;
            } catch (\Exception $e) {
                report($e);

                return Cache::get($backupKey, false);
            }
        });

        return $closes ?: [];
    }

    private function marketKey(string $id): string
    {
        return "crypto_market_{$id}";
    }

    /**
     * Descriptions for every language CoinGecko has are cached together,
     * under one entry per coin — the underlying request costs the same
     * whether we keep 1 language or all of them, so caching all of them
     * means a new $locale never needs a fresh fetch, only a different pick
     * from what's already cached.
     */
    public function getInfo(string $externalId, string $locale = 'en'): array
    {
        $cacheKey = "coingecko_info_{$externalId}";
        $empty = ['descriptions' => [], 'market_cap_usd' => null];

        $info = Cache::remember($cacheKey, 3600, function () use ($externalId, $empty) {
            try {
                // external_id is user-supplied and lands in the path.
                $response = Http::coingecko()->get('coins/'.rawurlencode($externalId), [
                    'localization' => 'true',
                    'tickers' => 'false',
                    'community_data' => 'false',
                    'developer_data' => 'false',
                    'sparkline' => 'false',
                ]);
                $response->throw();
                $data = $response->json();

                return [
                    'descriptions' => data_get($data, 'description', []),
                    'market_cap_usd' => data_get($data, 'market_data.market_cap.usd'),
                ];
            } catch (\Exception $e) {
                report($e);

                return $empty;
            }
        });

        return [
            'description' => ($info['descriptions'][$locale] ?? null) ?: ($info['descriptions']['en'] ?? null) ?: null,
            'market_cap_usd' => $info['market_cap_usd'],
        ];
    }
}
