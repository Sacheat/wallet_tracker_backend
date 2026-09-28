<?php

namespace App\Services\NewsProviders;

use App\Models\Asset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CryptoCompare's news endpoint now requires a (free, registration-only)
 * API key — it was keyless when this was first built, but access has
 * since moved under the CoinDesk API. Degrades to an empty list without
 * one, same pattern as the other providers.
 */
class CryptoCompareNewsProvider implements NewsProviderInterface
{
    public function getNews(Asset $asset): array
    {
        $apiKey = config('services.cryptocompare.key');

        if (empty($apiKey)) {
            Log::warning('CryptoCompare API key missing; skipping news lookup.');

            return [];
        }

        return Cache::remember("news_{$asset->symbol}", 1800, function () use ($asset, $apiKey) {
            try {
                // Header rather than ?api_key=: a connection error logs the URL.
                $response = Http::withHeaders(['authorization' => "Apikey {$apiKey}"])
                    ->get('https://min-api.cryptocompare.com/data/v2/news/', [
                        'lang' => 'EN',
                        'categories' => $asset->symbol,
                    ]);
                $response->throw();

                return collect($response->json('Data'))
                    ->take(5)
                    ->map(fn (array $item) => [
                        'title' => $item['title'],
                        'url' => $item['url'],
                        'source' => $item['source'],
                        'published_at' => now()->createFromTimestamp($item['published_on'])->toIso8601String(),
                    ])
                    ->values()
                    ->toArray();
            } catch (\Exception $e) {
                report($e);

                return [];
            }
        });
    }
}
