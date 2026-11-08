<?php

namespace Tests\Feature;

use App\Services\PriceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PriceService::getHistoricalUsdRates() prices each trade at the exchange
 * rate of its own date, which is what makes profit exact per currency.
 */
class HistoricalExchangeRatesTest extends TestCase
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
    private function rows(string $date): array
    {
        return [
            ['date' => $date, 'base' => 'USD', 'quote' => 'CHF', 'rate' => 0.88539],
            ['date' => $date, 'base' => 'USD', 'quote' => 'EUR', 'rate' => 0.91836],
            ['date' => $date, 'base' => 'USD', 'quote' => 'GBP', 'rate' => 0.7731],
        ];
    }

    public function test_asks_for_the_trade_date_and_only_the_supported_currencies(): void
    {
        Http::fake([self::RATES_URL => Http::response($this->rows('2025-03-14'))]);

        $rates = app(PriceService::class)->getHistoricalUsdRates(CarbonImmutable::parse('2025-03-14 18:30:00'));

        $this->assertEquals(['EUR' => 0.91836, 'GBP' => 0.7731, 'CHF' => 0.88539], $rates);
        Http::assertSent(fn (Request $request) => $request['date'] === '2025-03-14'
            && $request['base'] === 'USD'
            && $request['quotes'] === 'EUR,GBP,CHF');
    }

    public function test_a_past_day_is_fetched_only_once(): void
    {
        Http::fake([self::RATES_URL => Http::response($this->rows('2025-03-14'))]);
        $priceService = app(PriceService::class);

        $priceService->getHistoricalUsdRates(CarbonImmutable::parse('2025-03-14 09:00:00'));
        $priceService->getHistoricalUsdRates(CarbonImmutable::parse('2025-03-14 22:00:00'));

        Http::assertSentCount(1);
    }

    public function test_a_missing_currency_fails_instead_of_storing_a_partial_set(): void
    {
        // Before 1999 Frankfurter has no EUR at all (checked live).
        Http::fake([self::RATES_URL => Http::response([
            ['date' => '1998-06-01', 'base' => 'USD', 'quote' => 'CHF', 'rate' => 1.4783],
            ['date' => '1998-06-01', 'base' => 'USD', 'quote' => 'GBP', 'rate' => 0.61281],
        ])]);

        $this->expectException(\RuntimeException::class);

        app(PriceService::class)->getHistoricalUsdRates(CarbonImmutable::parse('1998-06-01'));
    }

    public function test_an_unreachable_api_surfaces_as_a_runtime_exception(): void
    {
        Http::fake([self::RATES_URL => Http::response('', 503)]);

        $this->expectException(\RuntimeException::class);

        app(PriceService::class)->getHistoricalUsdRates(CarbonImmutable::parse('2025-03-14'));
    }
}
