<?php

namespace App\Services\NewsProviders;

use App\Enums\AssetType;
use App\Models\Asset;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Stocks and ETFs only. Gold has no supported entity_type on Marketaux
 * (confirmed live: only equity/index/etf/mutualfund/currency/cryptocurrency)
 * and the free-text search fallback ("gold") returns mining-company news,
 * not commodity-price news — not reliable enough to show, so gold
 * deliberately gets no provider here rather than a misleading feed.
 */
class MarketauxNewsProvider implements NewsProviderInterface
{
    public function getNews(Asset $asset): array
    {
        $entityType = match ($asset->type) {
            AssetType::Stock => 'equity',
            AssetType::Etf => 'etf',
            default => null,
        };

        if ($entityType === null) {
            return [];
        }

        $apiKey = config('services.marketaux.key');

        if (empty($apiKey)) {
            Log::warning('Marketaux API key missing; skipping news lookup.');

            return [];
        }

        // 3h TTL, longer than CryptoCompare's 30 min: Marketaux's free tier
        // is a tighter budget (100 req/day, confirmed live, plus a shorter
        // burst limit on top) — sized to stay well under that even as the
        // catalog grows past its current handful of stock/ETF symbols.
        return Cache::remember("news_marketaux_{$asset->symbol}", 10800, function () use ($asset, $apiKey, $entityType) {
            try {
                // Bearer header rather than ?api_token= (accepted too, confirmed
                // live 2026-09-28): a connection error logs the URL.
                $response = Http::timeout(10)->withToken($apiKey)->get('https://api.marketaux.com/v1/news/all', [
                    'symbols' => $asset->symbol,
                    'entity_types' => $entityType,
                    'language' => 'fr',
                    'limit' => 5,
                ]);
                $response->throw();

                return collect($response->json('data'))
                    ->map(fn (array $item) => [
                        'title' => $item['title'],
                        'url' => $item['url'],
                        'source' => $item['source'],
                        'published_at' => $item['published_at'],
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
