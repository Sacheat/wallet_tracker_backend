<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Models\PortfolioTransaction;
use App\Services\PerformanceCalculator;
use Tests\TestCase;

/**
 * Figures below are worked out by hand, so the expected values document
 * the PMP method as much as they test it. Transactions are never saved:
 * no DB needed (none available here, see ExchangeRatesTest).
 */
class PerformanceCalculatorTest extends TestCase
{
    private const NEUTRAL_RATES = ['EUR' => 1.0, 'GBP' => 1.0, 'CHF' => 1.0];

    private function trade(TransactionType $type, float $quantity, ?float $unitPrice, float $fee = 0, string $currency = 'USD', ?array $fxRates = self::NEUTRAL_RATES): PortfolioTransaction
    {
        $transaction = new PortfolioTransaction([
            'type' => $type,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'fee' => $fee,
            'currency' => $currency,
        ]);
        $transaction->fx_rates = $fxRates;

        return $transaction;
    }

    public function test_weighted_average_cost_with_fees_and_a_partial_sell(): void
    {
        // Buys: 2 @ 100 + 2 fee, then 2 @ 200 → 4 held for 602, average 150.5.
        // Sell 1 @ 300 − 1.5 fee → realized 300 − 150.5 − 1.5 = 148; 3 left costing 451.5.
        // Worth 3 × 250 = 750 today → unrealized 298.5, i.e. 66.11 %.
        $performance = (new PerformanceCalculator)->forPosition([
            $this->trade(TransactionType::Buy, 2, 100, 2),
            $this->trade(TransactionType::Buy, 2, 200),
            $this->trade(TransactionType::Sell, 1, 300, 1.5),
        ], 750, self::NEUTRAL_RATES)['USD'];

        $this->assertSame([
            'cost_basis' => 451.5,
            'average_buy_price' => 150.5,
            'unrealized_pnl' => 298.5,
            'unrealized_pnl_percent' => 66.11,
            'realized_pnl' => 148.0,
        ], $performance);
    }

    public function test_the_currency_effect_shows_in_the_investors_own_currency(): void
    {
        // Bought for 1000 EUR when 1 USD = 0.80 EUR, i.e. 1250 USD. The asset
        // still trades at 1250 USD, but the dollar has risen to 1 USD = 1 EUR:
        // flat in USD, +25 % for someone who paid in euros.
        $performance = (new PerformanceCalculator)->forPosition([
            $this->trade(TransactionType::Buy, 1, 1000, 0, 'EUR', ['EUR' => 0.8, 'GBP' => 0.7, 'CHF' => 0.9]),
        ], 1250, ['EUR' => 1.0, 'GBP' => 0.75, 'CHF' => 0.9]);

        $this->assertSame(1250.0, $performance['USD']['cost_basis']);
        $this->assertSame(0.0, $performance['USD']['unrealized_pnl']);
        $this->assertSame(1000.0, $performance['EUR']['cost_basis']);
        $this->assertSame(250.0, $performance['EUR']['unrealized_pnl']);
        $this->assertSame(25.0, $performance['EUR']['unrealized_pnl_percent']);
    }

    public function test_a_single_trade_without_price_makes_the_profit_unknown(): void
    {
        $performance = (new PerformanceCalculator)->forPosition([
            $this->trade(TransactionType::Buy, 1, null, fxRates: null),
            $this->trade(TransactionType::Buy, 1, 100),
        ], 300, self::NEUTRAL_RATES);

        $this->assertNull($performance);
    }

    public function test_a_closed_position_keeps_only_its_realized_profit(): void
    {
        $performance = (new PerformanceCalculator)->forPosition([
            $this->trade(TransactionType::Buy, 0.1, 100),
            $this->trade(TransactionType::Buy, 0.2, 100),
            $this->trade(TransactionType::Sell, 0.3, 150),
        ], 0, self::NEUTRAL_RATES)['USD'];

        $this->assertSame([
            'cost_basis' => 0.0,
            'average_buy_price' => null,
            'unrealized_pnl' => 0.0,
            'unrealized_pnl_percent' => null,
            'realized_pnl' => 15.0,
        ], $performance);
    }

    public function test_portfolio_total_leaves_out_positions_of_unknown_profit(): void
    {
        $calculator = new PerformanceCalculator;
        $known = $calculator->forPosition([$this->trade(TransactionType::Buy, 1, 100)], 150, self::NEUTRAL_RATES);
        $other = $calculator->forPosition([$this->trade(TransactionType::Buy, 1, 300)], 250, self::NEUTRAL_RATES);

        $total = $calculator->total([$known, null, $other])['USD'];

        $this->assertSame([
            'cost_basis' => 400.0,
            'unrealized_pnl' => 0.0,
            'unrealized_pnl_percent' => 0.0,
            'realized_pnl' => 0.0,
        ], $total);
    }
}
