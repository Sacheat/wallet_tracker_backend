<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Services\PriceService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PriceService::getUsdToCurrencyRatesWithTimestamp() is what backs
 * GET /api/exchange-rates. No route/DB test here: this environment has no
 * pdo_sqlite for RefreshDatabase, and the endpoint itself is a thin
 * pass-through with no logic of its own to cover beyond this.
 */
class ExchangeRatesTest extends TestCase
{
    private const RATES_URL = 'api.frankfurter.dev/v2/rates*';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function frankfurterRows(): array
    {
        return [
            ['date' => '2026-09-22', 'base' => 'USD', 'quote' => 'EUR', 'rate' => 0.92],
            ['date' => '2026-09-22', 'base' => 'USD', 'quote' => 'GBP', 'rate' => 0.79],
            ['date' => '2026-09-22', 'base' => 'USD', 'quote' => 'CHF', 'rate' => 0.88],
            ['date' => '2026-09-22', 'base' => 'USD', 'quote' => 'JPY', 'rate' => 147.0],
        ];
    }

    public function test_rates_and_timestamp_come_from_a_single_fetch(): void
    {
        Http::fake([self::RATES_URL => Http::response($this->frankfurterRows())]);
        $priceService = app(PriceService::class);

        $snapshot = $priceService->getUsdToCurrencyRatesWithTimestamp(['EUR', 'GBP', 'CHF']);

        $this->assertEquals(['EUR' => 0.92, 'GBP' => 0.79, 'CHF' => 0.88], $snapshot['rates']);
        $this->assertNotNull($snapshot['updated_at']);
        Http::assertSentCount(1);
    }

    public function test_shares_its_cache_with_the_portfolio_rate_lookup(): void
    {
        Http::fake([self::RATES_URL => Http::response($this->frankfurterRows())]);
        $priceService = app(PriceService::class);

        $priceService->getUsdToCurrencyRates(['EUR']);
        $snapshot = $priceService->getUsdToCurrencyRatesWithTimestamp(['EUR', 'GBP', 'CHF']);

        $this->assertEquals(0.92, $snapshot['rates']['EUR']);
        Http::assertSentCount(1);
    }

    public function test_a_currency_missing_from_the_response_comes_back_null(): void
    {
        Http::fake([self::RATES_URL => Http::response($this->frankfurterRows())]);
        $priceService = app(PriceService::class);

        $snapshot = $priceService->getUsdToCurrencyRatesWithTimestamp(['EUR', 'XYZ']);

        $this->assertSame(0.92, $snapshot['rates']['EUR']);
        $this->assertNull($snapshot['rates']['XYZ']);
    }

    public function test_usd_conversion_never_touches_the_network(): void
    {
        // No Http::fake() at all: preventStrayRequests() means any actual
        // HTTP attempt fails the test, proving the USD short-circuit works.
        $priceService = app(PriceService::class);

        $this->assertSame(150.0, $priceService->convertToUsd(150.0, Currency::Usd));
    }

    public function test_a_non_usd_amount_is_divided_by_its_usd_rate(): void
    {
        Http::fake([self::RATES_URL => Http::response($this->frankfurterRows())]);
        $priceService = app(PriceService::class);

        // 92 EUR at a rate of 0.92 EUR per USD is 100 USD.
        $usd = $priceService->convertToUsd(92.0, Currency::Eur);

        $this->assertSame(100.0, $usd);
    }
}
