<?php

namespace Tests\Feature;

use App\Services\PriceProviders\YahooFinancePriceProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YahooFinancePriceProviderTest extends TestCase
{
    private const SPARK_URL = 'query1.finance.yahoo.com/v8/finance/spark*';

    private const CHART_URL = 'query1.finance.yahoo.com/v8/finance/chart/*';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Hourly closes over the last 8 days ending at $price; one hour ago the
     * close was $price / 1.02, so the 1h change is +2 %.
     *
     * @return array{timestamp: array<int, int>, close: array<int, float>}
     */
    private function sparkEntry(float $price): array
    {
        $timestamps = [];
        $closes = [];
        for ($hoursAgo = 8 * 24; $hoursAgo >= 0; $hoursAgo--) {
            $timestamps[] = now()->subHours($hoursAgo)->timestamp;
            $closes[] = $hoursAgo === 0 ? $price : round($price / 1.02, 6);
        }

        return ['timestamp' => $timestamps, 'close' => $closes];
    }

    /**
     * Answers like the real spark endpoint: one entry per requested symbol,
     * except those listed in $unknown.
     *
     * @param  array<int, string>  $unknown
     */
    private function fakeSpark(array $unknown = []): void
    {
        Http::fake([
            self::SPARK_URL => function (Request $request) use ($unknown) {
                return Http::response(collect(explode(',', $request['symbols']))
                    ->reject(fn (string $symbol) => in_array($symbol, $unknown, true))
                    ->mapWithKeys(fn (string $symbol) => [$symbol => $this->sparkEntry(100.0)])
                    ->all());
            },
            self::CHART_URL => Http::response(['chart' => ['result' => [[
                'meta' => ['regularMarketPrice' => 50.0],
                'timestamp' => [now()->timestamp],
                'indicators' => ['quote' => [['close' => [50.0]]]],
            ]]]]),
        ]);
    }

    public function test_refresh_batches_symbols_by_twenty_and_later_reads_cost_nothing(): void
    {
        $this->fakeSpark();
        $provider = new YahooFinancePriceProvider;
        $symbols = array_map(fn (int $n) => "TICK{$n}", range(1, 25));

        $provider->refresh($symbols);
        $prices = $provider->getUsdPrices($symbols);
        $changes = $provider->getPercentChanges(['TICK3']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'spark') && count(explode(',', $request['symbols'])) === 20);
        $this->assertCount(25, array_filter($prices));
        $this->assertEquals(100.0, $prices['TICK25']);
        $this->assertEqualsWithDelta(2.0, $changes['TICK3']['1h'], 0.01);
        $this->assertEqualsWithDelta(2.0, $changes['TICK3']['7d'], 0.01);
    }

    public function test_a_symbol_missing_from_the_batch_falls_back_to_its_own_chart_on_read(): void
    {
        $this->fakeSpark(unknown: ['GC=F']);
        $provider = new YahooFinancePriceProvider;

        $provider->refresh(['AAPL', 'GC=F']);
        $prices = $provider->getUsdPrices(['AAPL', 'GC=F']);

        $this->assertEquals(100.0, $prices['AAPL']);
        $this->assertEquals(50.0, $prices['GC=F']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'chart/GC%3DF'));
    }

    public function test_a_failed_batch_keeps_the_previous_prices(): void
    {
        Http::fake([
            self::SPARK_URL => Http::sequence()
                ->push(['AAPL' => $this->sparkEntry(100.0)])
                ->push('rate limited', 429),
        ]);
        $provider = new YahooFinancePriceProvider;

        $provider->refresh(['AAPL']);
        $provider->refresh(['AAPL']);

        $this->assertEquals(100.0, $provider->getUsdPrices(['AAPL'])['AAPL']);
        Http::assertSentCount(2);
    }
}
