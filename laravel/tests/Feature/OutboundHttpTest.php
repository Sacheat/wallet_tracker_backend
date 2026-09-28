<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Services\AlertParserService;
use App\Services\AssetSearchService;
use App\Services\NewsProviders\CryptoCompareNewsProvider;
use App\Services\NewsProviders\MarketauxNewsProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fixes from the first production run (2026-09-28): IPv4-only egress,
 * provider keys kept out of URLs (a connection error logs the full URL),
 * and no caching of a search a provider failed on. No DB (see
 * ExchangeRatesTest).
 */
class OutboundHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function assertKeyOnlyInHeader(string $header, string $expected): void
    {
        Http::assertSent(fn (Request $request) => $request->header($header) === [$expected]
            && ! str_contains($request->url(), 'secret-key'));
    }

    public function test_outbound_requests_are_forced_to_ipv4(): void
    {
        $this->assertSame('v4', Http::getOptions()['force_ip_resolve'] ?? null);
    }

    public function test_gemini_key_is_sent_as_a_header_not_in_the_url(): void
    {
        config(['services.gemini.key' => 'secret-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('high demand', 503)]);

        app(AlertParserService::class)->parse('préviens-moi si mon portefeuille passe sous 5000');

        $this->assertKeyOnlyInHeader('x-goog-api-key', 'secret-key');
    }

    public function test_cryptocompare_key_is_sent_as_a_header_not_in_the_url(): void
    {
        config(['services.cryptocompare.key' => 'secret-key']);
        Http::fake(['min-api.cryptocompare.com/*' => Http::response(['Data' => []])]);

        (new CryptoCompareNewsProvider)->getNews(new Asset(['symbol' => 'BTC', 'type' => AssetType::Crypto]));

        $this->assertKeyOnlyInHeader('authorization', 'Apikey secret-key');
    }

    public function test_marketaux_key_is_sent_as_a_header_not_in_the_url(): void
    {
        config(['services.marketaux.key' => 'secret-key']);
        Http::fake(['api.marketaux.com/*' => Http::response(['data' => []])]);

        (new MarketauxNewsProvider)->getNews(new Asset(['symbol' => 'AAPL', 'type' => AssetType::Stock]));

        $this->assertKeyOnlyInHeader('Authorization', 'Bearer secret-key');
    }

    public function test_search_is_not_cached_when_a_provider_fails(): void
    {
        Http::fake([
            'api.coingecko.com/*' => Http::sequence()
                ->push('rate limited', 429)
                ->push(['coins' => [['id' => 'bitcoin', 'name' => 'Bitcoin', 'symbol' => 'btc']]]),
            'query1.finance.yahoo.com/*' => Http::response(['quotes' => []]),
        ]);
        $service = app(AssetSearchService::class);

        $this->assertSame([], $service->search('bitcoin'));
        $this->assertFalse(Cache::has('asset_search_'.md5('bitcoin')));

        $this->assertSame('bitcoin', $service->search('bitcoin')[0]['external_id']);
        $this->assertTrue(Cache::has('asset_search_'.md5('bitcoin')));
    }

    public function test_coingecko_demo_key_is_sent_as_a_header_when_configured(): void
    {
        config(['services.coingecko.key' => 'secret-key']);
        Http::fake(['api.coingecko.com/*' => Http::response(['coins' => []])]);

        Http::coingecko()->get('search', ['query' => 'bitcoin']);

        $this->assertKeyOnlyInHeader('x-cg-demo-api-key', 'secret-key');
    }

    public function test_coingecko_stays_keyless_without_a_configured_key(): void
    {
        config(['services.coingecko.key' => null]);
        Http::fake(['api.coingecko.com/*' => Http::response(['coins' => []])]);

        Http::coingecko()->get('search', ['query' => 'bitcoin']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.coingecko.com/api/v3/search?query=bitcoin'
            && ! $request->hasHeader('x-cg-demo-api-key'));
    }
}
