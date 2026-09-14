<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Models\Asset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NewsService
{
    /**
     * Crypto only: no free equivalent for stocks/gold, and Alpha Vantage's
     * NEWS_SENTIMENT would compete with the already-tight 25 req/day budget
     * used for stock price snapshots (step 3).
     *
     * CryptoCompare's news endpoint now requires a (free, registration-only)
     * API key — it was keyless when this was first built, but access has
     * since moved under the CoinDesk API. Degrades to an empty list without
     * one, same pattern as the other providers.
     *
     * @return array<int, array{title: string, url: string, source: string, published_at: Carbon}>
     */
    public function getNewsForAsset(Asset $asset): array
    {
        if ($asset->type !== AssetType::Crypto) {
            return [];
        }

        $apiKey = config('services.cryptocompare.key');

        if (empty($apiKey)) {
            Log::warning('CryptoCompare API key missing; skipping news lookup.');

            return [];
        }

        return Cache::remember("news_{$asset->symbol}", 1800, function () use ($asset, $apiKey) {
            try {
                $response = Http::get('https://min-api.cryptocompare.com/data/v2/news/', [
                    'lang' => 'EN',
                    'categories' => $asset->symbol,
                    'api_key' => $apiKey,
                ]);
                $response->throw();

                return collect($response->json('Data'))
                    ->take(5)
                    ->map(fn (array $item) => [
                        'title' => $item['title'],
                        'url' => $item['url'],
                        'source' => $item['source'],
                        'published_at' => now()->createFromTimestamp($item['published_on']),
                    ])
                    ->values()
                    ->toArray();
            } catch (\Exception $e) {
                report($e);

                return [];
            }
        });
    }

    /**
     * @param  Collection<int, Asset>  $assets
     * @return array<int, array{title: string, url: string, source: string, published_at: Carbon, asset_symbol: string}>
     */
    public function getNewsForAssets(Collection $assets): array
    {
        return $assets
            ->filter(fn (Asset $asset) => $asset->type === AssetType::Crypto)
            ->unique('id')
            ->flatMap(function (Asset $asset) {
                return collect($this->getNewsForAsset($asset))
                    ->map(fn (array $item) => [...$item, 'asset_symbol' => $asset->symbol]);
            })
            ->unique('url')
            ->sortByDesc('published_at')
            ->take(20)
            ->values()
            ->toArray();
    }
}
