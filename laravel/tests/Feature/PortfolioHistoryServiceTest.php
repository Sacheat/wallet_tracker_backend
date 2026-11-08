<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Enums\TransactionType;
use App\Models\Asset;
use App\Models\PortfolioEntry;
use App\Models\PortfolioTransaction;
use App\Services\PortfolioHistoryService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Positions are built in memory (no DB available here, see
 * ExchangeRatesTest) and every outbound call is faked. Range 7d with
 * "today" frozen on Sunday 2026-11-08 covers 2026-11-02 → 2026-11-08.
 */
class PortfolioHistoryServiceTest extends TestCase
{
    private const DAYS = ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', '2026-11-08'];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->travelTo('2026-11-08 15:00:00');
    }

    /**
     * USD→EUR is 0.5 every day of the range, GBP/CHF 1.
     */
    private function fakeRates(): void
    {
        Http::fake(['api.frankfurter.dev/v2/rates*' => Http::response(collect(self::DAYS)->flatMap(fn (string $day) => [
            ['date' => $day, 'base' => 'USD', 'quote' => 'EUR', 'rate' => 0.5],
            ['date' => $day, 'base' => 'USD', 'quote' => 'GBP', 'rate' => 1.0],
            ['date' => $day, 'base' => 'USD', 'quote' => 'CHF', 'rate' => 1.0],
        ])->all())]);
    }

    /**
     * @param  array<string, float>  $closes  Keyed by Y-m-d.
     */
    private function fakeCoinGecko(string $id, array $closes): void
    {
        Http::fake(["api.coingecko.com/api/v3/coins/{$id}/market_chart*" => Http::response([
            'prices' => collect($closes)->map(fn (float $price, string $day) => [strtotime("{$day} 00:00:00 UTC") * 1000, $price])->values()->all(),
        ])]);
    }

    private function entry(string $name, AssetType $type, string $externalId, PortfolioTransaction ...$transactions): PortfolioEntry
    {
        $entry = new PortfolioEntry;
        $entry->setRelation('asset', new Asset(['name' => $name, 'symbol' => $externalId, 'type' => $type, 'external_id' => $externalId]));
        $entry->setRelation('transactions', collect($transactions));

        return $entry;
    }

    private function trade(TransactionType $type, float $quantity, ?float $unitPrice, string $executedAt, array $fxRates = ['EUR' => 0.8, 'GBP' => 1.0, 'CHF' => 1.0]): PortfolioTransaction
    {
        $transaction = new PortfolioTransaction([
            'type' => $type,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'fee' => 0,
            'currency' => 'USD',
            'executed_at' => $executedAt,
        ]);
        $transaction->fx_rates = $unitPrice === null ? null : $fxRates;

        return $transaction;
    }

    /**
     * @param  array<string, mixed>  $history
     * @return array<string, array<string, mixed>> Points keyed by date.
     */
    private function byDate(array $history): array
    {
        return collect($history['points'])->keyBy('date')->all();
    }

    public function test_value_follows_the_quantity_held_each_day_and_invested_follows_the_cost(): void
    {
        $this->fakeRates();
        $this->fakeCoinGecko('bitcoin', [
            '2026-11-01' => 90, '2026-11-02' => 95, '2026-11-03' => 100, '2026-11-04' => 110,
            '2026-11-05' => 120, '2026-11-06' => 130, '2026-11-07' => 140, '2026-11-08' => 150,
        ]);

        // Buy 2 @ 100 on the 4th (1 USD = 0.8 EUR that day), sell 1 on the 6th.
        $points = $this->byDate(app(PortfolioHistoryService::class)->build(collect([
            $this->entry('Bitcoin', AssetType::Crypto, 'bitcoin',
                $this->trade(TransactionType::Buy, 2, 100, '2026-11-04 09:00:00'),
                $this->trade(TransactionType::Sell, 1, 130, '2026-11-06 18:00:00'),
            ),
        ]), '7d'));

        $this->assertSame(array_values(self::DAYS), array_keys($points));
        $this->assertSame(0.0, $points['2026-11-03']['value']['USD']);
        $this->assertSame(0.0, $points['2026-11-03']['invested']['USD']);
        $this->assertSame(220.0, $points['2026-11-04']['value']['USD']);
        $this->assertSame(200.0, $points['2026-11-04']['invested']['USD']);
        // Invested in EUR uses the trade date's rate (0.8), value uses the day's (0.5).
        $this->assertSame(160.0, $points['2026-11-04']['invested']['EUR']);
        $this->assertSame(110.0, $points['2026-11-04']['value']['EUR']);
        // After the sell: 1 left, average cost 100.
        $this->assertSame(130.0, $points['2026-11-06']['value']['USD']);
        $this->assertSame(100.0, $points['2026-11-06']['invested']['USD']);
        $this->assertSame(150.0, $points['2026-11-08']['value']['USD']);
    }

    public function test_market_closed_days_carry_the_last_close_forward(): void
    {
        $this->fakeRates();
        $timestamps = [strtotime('2026-11-05 14:30:00 UTC'), strtotime('2026-11-06 14:30:00 UTC')];
        Http::fake(['query1.finance.yahoo.com/v8/finance/chart/*' => Http::response(['chart' => ['result' => [[
            'timestamp' => $timestamps,
            'indicators' => ['quote' => [['close' => [50.0, 52.0]]]],
        ]]]])]);

        $points = $this->byDate(app(PortfolioHistoryService::class)->build(collect([
            $this->entry('Apple', AssetType::Stock, 'AAPL', $this->trade(TransactionType::Buy, 10, 40, '2026-10-01 10:00:00')),
        ]), '7d'));

        // Before the first close in the series: priced at the first one.
        $this->assertSame(500.0, $points['2026-11-02']['value']['USD']);
        // Saturday and Sunday: Friday's close.
        $this->assertSame(520.0, $points['2026-11-07']['value']['USD']);
        $this->assertSame(520.0, $points['2026-11-08']['value']['USD']);
    }

    public function test_a_trade_without_price_hides_invested_from_that_day_only(): void
    {
        $this->fakeRates();
        $this->fakeCoinGecko('ethereum', array_fill_keys(self::DAYS, 10.0));

        $points = $this->byDate(app(PortfolioHistoryService::class)->build(collect([
            $this->entry('Ethereum', AssetType::Crypto, 'ethereum', $this->trade(TransactionType::Buy, 3, null, '2026-11-05 08:00:00')),
        ]), '7d'));

        $this->assertSame(0.0, $points['2026-11-04']['invested']['USD']);
        $this->assertNull($points['2026-11-05']['invested']);
        $this->assertSame(30.0, $points['2026-11-05']['value']['USD']);
    }

    public function test_an_asset_without_history_is_reported_instead_of_counted_as_zero(): void
    {
        $this->fakeRates();
        Http::fake(['api.coingecko.com/api/v3/coins/unknown-coin/market_chart*' => Http::response([], 404)]);

        $history = app(PortfolioHistoryService::class)->build(collect([
            $this->entry('Unknown', AssetType::Crypto, 'unknown-coin', $this->trade(TransactionType::Buy, 1, 5, '2026-11-01 08:00:00')),
        ]), '7d');

        $this->assertSame(['Unknown'], $history['missing_history']);
        $this->assertSame(0.0, $this->byDate($history)['2026-11-08']['value']['USD']);
    }

    public function test_exchange_rates_being_unreachable_fails_loudly(): void
    {
        Http::fake(['api.frankfurter.dev/*' => Http::response('', 503)]);

        $this->expectException(\RuntimeException::class);

        app(PortfolioHistoryService::class)->build(collect(), '7d');
    }
}
