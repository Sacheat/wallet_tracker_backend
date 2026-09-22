<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Services\NewsProviders\MarketauxNewsProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * No DB here (see ExchangeRatesTest) — Asset instances are in-memory, never
 * saved.
 */
class MarketauxNewsProviderTest extends TestCase
{
    private const MARKETAUX_URL = 'api.marketaux.com/v1/news/all*';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.marketaux.key' => 'fake-key']);
    }

    private function stock(string $symbol = 'AAPL'): Asset
    {
        $asset = new Asset(['name' => 'Apple Inc.', 'symbol' => $symbol, 'type' => AssetType::Stock]);
        $asset->id = 1;

        return $asset;
    }

    private function fakeArticles(): array
    {
        return [
            'data' => [
                [
                    'title' => 'Apple annonce de nouveaux produits',
                    'url' => 'https://example.com/apple-news',
                    'source' => 'example.com',
                    'published_at' => '2026-09-22T10:00:00.000000Z',
                ],
            ],
        ];
    }

    public function test_a_stock_returns_news_from_marketaux(): void
    {
        Http::fake([self::MARKETAUX_URL => Http::response($this->fakeArticles())]);
        $provider = app(MarketauxNewsProvider::class);

        $news = $provider->getNews($this->stock());

        $this->assertCount(1, $news);
        $this->assertSame('Apple annonce de nouveaux produits', $news[0]['title']);
        Http::assertSent(fn ($request) => $request['symbols'] === 'AAPL' && $request['entity_types'] === 'equity');
    }

    public function test_an_etf_uses_the_etf_entity_type(): void
    {
        Http::fake([self::MARKETAUX_URL => Http::response($this->fakeArticles())]);
        $provider = app(MarketauxNewsProvider::class);
        $etf = new Asset(['name' => 'Vanguard S&P 500', 'symbol' => 'VOO', 'type' => AssetType::Etf]);

        $provider->getNews($etf);

        Http::assertSent(fn ($request) => $request['entity_types'] === 'etf');
    }

    public function test_gold_never_makes_a_request(): void
    {
        $provider = app(MarketauxNewsProvider::class);
        $gold = new Asset(['name' => 'Or', 'symbol' => 'XAU', 'type' => AssetType::Gold]);

        $news = $provider->getNews($gold);

        $this->assertSame([], $news);
    }

    public function test_without_an_api_key_it_degrades_to_empty_without_any_request(): void
    {
        config(['services.marketaux.key' => null]);
        $provider = app(MarketauxNewsProvider::class);

        $news = $provider->getNews($this->stock());

        $this->assertSame([], $news);
    }

    public function test_a_failed_request_degrades_to_empty_instead_of_throwing(): void
    {
        Http::fake([self::MARKETAUX_URL => Http::response('rate limited', 429)]);
        $provider = app(MarketauxNewsProvider::class);

        $news = $provider->getNews($this->stock());

        $this->assertSame([], $news);
    }

    public function test_results_are_cached_per_symbol(): void
    {
        Http::fake([self::MARKETAUX_URL => Http::response($this->fakeArticles())]);
        $provider = app(MarketauxNewsProvider::class);

        $provider->getNews($this->stock());
        $provider->getNews($this->stock());

        Http::assertSentCount(1);
    }
}
