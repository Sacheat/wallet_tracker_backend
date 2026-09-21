<?php

namespace App\Services;

use App\Enums\AssetType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Lets the catalog "add asset" form look up the right external_id instead of
 * requiring it to be known by hand (CoinGecko's coin id, e.g. "bitcoin" not
 * "btc"; a Yahoo Finance ticker for stocks/ETFs). No provider for gold: it's
 * a single well-known futures ticker (GC=F, see YahooFinancePriceProvider),
 * not something worth searching for.
 */
class AssetSearchService
{
    /**
     * @return array<int, array{external_id: string, name: string, symbol: string, type: string}>
     */
    public function search(string $query): array
    {
        return Cache::remember('asset_search_'.md5(strtolower($query)), 300, fn () => [
            ...$this->searchCrypto($query),
            ...$this->searchStocksAndEtfs($query),
        ]);
    }

    private function searchCrypto(string $query): array
    {
        try {
            $response = Http::get('https://api.coingecko.com/api/v3/search', ['query' => $query]);
            $response->throw();

            return collect(data_get($response->json(), 'coins', []))
                ->take(8)
                ->map(fn (array $coin) => [
                    'external_id' => $coin['id'],
                    'name' => $coin['name'],
                    'symbol' => strtoupper($coin['symbol']),
                    'type' => AssetType::Crypto->value,
                ])
                ->values()
                ->all();
        } catch (\Exception $e) {
            report($e);

            return [];
        }
    }

    private function searchStocksAndEtfs(string $query): array
    {
        $typeByQuoteType = [
            'EQUITY' => AssetType::Stock->value,
            'ETF' => AssetType::Etf->value,
        ];

        try {
            $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0'])
                ->get('https://query1.finance.yahoo.com/v1/finance/search', [
                    'q' => $query,
                    'quotesCount' => 8,
                    'newsCount' => 0,
                ]);
            $response->throw();

            return collect(data_get($response->json(), 'quotes', []))
                ->filter(fn (array $quote) => isset($typeByQuoteType[$quote['quoteType'] ?? null]))
                ->map(fn (array $quote) => [
                    'external_id' => $quote['symbol'],
                    'name' => $quote['longname'] ?? $quote['shortname'] ?? $quote['symbol'],
                    'symbol' => $quote['symbol'],
                    'type' => $typeByQuoteType[$quote['quoteType']],
                ])
                ->values()
                ->all();
        } catch (\Exception $e) {
            report($e);

            return [];
        }
    }
}
