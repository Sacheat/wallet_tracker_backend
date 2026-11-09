<?php

namespace Tests\Feature;

use App\Services\PriceProviders\CoinGeckoPriceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoinGeckoPriceProviderTest extends TestCase
{
    private const MARKETS_URL = 'api.coingecko.com/api/v3/coins/markets*';

    private const COIN_URL = 'api.coingecko.com/api/v3/coins/bitcoin*';

    protected function setUp(): void
    {
        parent::setUp();

        // A test that forgets to fake a request must fail, not hit the real API.
        Http::preventStrayRequests();
    }

    /**
     * Answers /coins/markets like the real API: one row per requested id,
     * except ids listed in $unknown, which it silently leaves out.
     *
     * @param  array<int, string>  $unknown
     */
    private function fakeMarkets(array $unknown = []): void
    {
        Http::fake([
            self::MARKETS_URL => function (Request $request) use ($unknown) {
                parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

                $rows = collect(explode(',', $query['ids']))
                    ->reject(fn (string $id) => in_array($id, $unknown, true))
                    ->map(fn (string $id) => $this->marketRow($id))
                    ->values()
                    ->all();

                return Http::response($rows);
            },
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function marketRow(string $id): array
    {
        return [
            'id' => $id,
            'current_price' => 100.0,
            'price_change_percentage_1h_in_currency' => 0.5,
            'price_change_percentage_24h_in_currency' => 2.0,
            'price_change_percentage_7d_in_currency' => -3.0,
        ];
    }

    /**
     * @return array<int, string> The `ids` query value of every request sent.
     */
    private function requestedIds(): array
    {
        return Http::recorded()
            ->map(function (array $pair) {
                parse_str(parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

                return $query['ids'];
            })
            ->values()
            ->all();
    }

    public function test_prices_and_changes_for_the_same_coins_cost_a_single_request(): void
    {
        $this->fakeMarkets();
        $provider = new CoinGeckoPriceProvider;
        $ids = ['bitcoin', 'ethereum', 'solana'];

        $prices = $provider->getUsdPrices($ids);
        $changes = [];
        foreach ($ids as $id) {
            $changes += $provider->getPercentChanges([$id]);
        }

        $this->assertSame(['bitcoin', 'ethereum', 'solana'], array_keys($prices));
        $this->assertEquals(100.0, $prices['ethereum']);
        $this->assertEquals(['1h' => 0.5, '24h' => 2.0, '7d' => -3.0], $changes['solana']);
        Http::assertSentCount(1);
    }

    public function test_only_the_coins_missing_from_the_cache_are_fetched(): void
    {
        $this->fakeMarkets();
        $provider = new CoinGeckoPriceProvider;

        $provider->getUsdPrices(['bitcoin']);
        $prices = $provider->getUsdPrices(['bitcoin', 'ethereum']);

        $this->assertSame(['bitcoin', 'ethereum'], array_keys($prices));
        $this->assertSame(['bitcoin', 'ethereum'], $this->requestedIds());
    }

    public function test_a_failed_request_is_not_repeated_within_the_ttl(): void
    {
        Http::fake([self::MARKETS_URL => Http::response('rate limited', 429)]);
        $provider = new CoinGeckoPriceProvider;

        $first = $provider->getUsdPrices(['bitcoin', 'ethereum']);
        $second = $provider->getPercentChanges(['bitcoin']);

        $this->assertSame([], $first);
        $this->assertSame([], $second);
        Http::assertSentCount(1);
    }

    public function test_a_failed_request_serves_each_coins_last_good_copy(): void
    {
        Http::fake([
            self::MARKETS_URL => Http::sequence()
                ->push([$this->marketRow('bitcoin')])
                ->push('rate limited', 429),
        ]);
        $provider = new CoinGeckoPriceProvider;
        $provider->getUsdPrices(['bitcoin']);

        // Entry expired, API now rate limited: bitcoin has a backup, ethereum never had one.
        Cache::forget('crypto_market_bitcoin');

        $prices = $provider->getUsdPrices(['bitcoin', 'ethereum']);

        $this->assertSame(['bitcoin'], array_keys($prices));
        $this->assertEquals(100.0, $prices['bitcoin']);
    }

    public function test_a_coin_the_api_does_not_know_is_left_out_and_not_refetched(): void
    {
        $this->fakeMarkets(unknown: ['not-a-coin']);
        $provider = new CoinGeckoPriceProvider;

        $first = $provider->getUsdPrices(['bitcoin', 'not-a-coin']);
        $second = $provider->getUsdPrices(['bitcoin', 'not-a-coin']);

        $this->assertSame(['bitcoin'], array_keys($first));
        $this->assertSame(['bitcoin'], array_keys($second));
        Http::assertSentCount(1);
    }

    public function test_more_than_250_coins_are_split_across_requests(): void
    {
        $this->fakeMarkets();
        $provider = new CoinGeckoPriceProvider;
        $ids = array_map(fn (int $n) => "coin-{$n}", range(1, 300));

        $prices = $provider->getUsdPrices($ids);

        $this->assertCount(300, $prices);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request['per_page'] === 250);
    }

    public function test_refresh_uses_the_keyless_api_and_later_reads_cost_nothing(): void
    {
        config(['services.coingecko.key' => 'demo-key']);
        $this->fakeMarkets();
        $provider = new CoinGeckoPriceProvider;

        $provider->refresh(['bitcoin', 'ethereum']);
        $prices = $provider->getUsdPrices(['bitcoin', 'ethereum']);
        $changes = $provider->getPercentChanges(['bitcoin']);

        $this->assertEquals(100.0, $prices['ethereum']);
        $this->assertEquals(2.0, $changes['bitcoin']['24h']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('x-cg-demo-api-key'));
    }

    public function test_refresh_refetches_coins_already_in_the_cache(): void
    {
        $this->fakeMarkets();
        $provider = new CoinGeckoPriceProvider;

        $provider->getUsdPrices(['bitcoin']);
        $provider->refresh(['bitcoin']);

        Http::assertSentCount(2);
    }

    public function test_refresh_retries_with_the_demo_key_when_the_public_api_refuses(): void
    {
        config(['services.coingecko.key' => 'demo-key']);
        Http::fake([
            self::MARKETS_URL => Http::sequence()
                ->push('rate limited', 429)
                ->push([$this->marketRow('bitcoin')]),
        ]);
        $provider = new CoinGeckoPriceProvider;

        $provider->refresh(['bitcoin']);

        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->header('x-cg-demo-api-key'))->values()->all();
        $this->assertSame([[], ['demo-key']], $sent);
        $this->assertEquals(100.0, $provider->getUsdPrices(['bitcoin'])['bitcoin']);
    }

    public function test_without_a_demo_key_a_refused_refresh_is_not_retried(): void
    {
        config(['services.coingecko.key' => null]);
        Http::fake([self::MARKETS_URL => Http::response('rate limited', 429)]);
        $provider = new CoinGeckoPriceProvider;

        $provider->refresh(['bitcoin']);

        Http::assertSentCount(1);
    }

    public function test_regular_reads_keep_using_the_demo_key(): void
    {
        config(['services.coingecko.key' => 'demo-key']);
        $this->fakeMarkets();
        $provider = new CoinGeckoPriceProvider;

        $provider->getUsdPrices(['bitcoin']);

        Http::assertSent(fn (Request $request) => $request->header('x-cg-demo-api-key') === ['demo-key']);
    }

    public function test_the_requested_locales_description_is_returned(): void
    {
        Http::fake([self::COIN_URL => Http::response([
            'description' => ['en' => 'Bitcoin is digital gold.', 'fr' => "Bitcoin est de l'or numérique."],
            'market_data' => ['market_cap' => ['usd' => 1_000_000]],
        ])]);
        $provider = new CoinGeckoPriceProvider;

        $info = $provider->getInfo('bitcoin', 'fr');

        $this->assertSame("Bitcoin est de l'or numérique.", $info['description']);
        $this->assertEquals(1_000_000, $info['market_cap_usd']);
    }

    public function test_an_empty_translation_falls_back_to_english(): void
    {
        Http::fake([self::COIN_URL => Http::response([
            'description' => ['en' => 'Pepe is a meme coin.', 'fr' => ''],
        ])]);
        $provider = new CoinGeckoPriceProvider;

        $info = $provider->getInfo('bitcoin', 'fr');

        $this->assertSame('Pepe is a meme coin.', $info['description']);
    }

    public function test_all_languages_are_cached_together_so_a_new_locale_costs_no_extra_request(): void
    {
        Http::fake([self::COIN_URL => Http::response([
            'description' => ['en' => 'Bitcoin is digital gold.', 'fr' => "Bitcoin est de l'or numérique."],
        ])]);
        $provider = new CoinGeckoPriceProvider;

        $provider->getInfo('bitcoin', 'fr');
        $info = $provider->getInfo('bitcoin', 'en');

        $this->assertSame('Bitcoin is digital gold.', $info['description']);
        Http::assertSentCount(1);
    }
}
