<?php

namespace Tests\Unit;

use App\Enums\TransactionType;
use App\Services\PortfolioTransactionService;
use PHPUnit\Framework\TestCase;

/**
 * The oversell guard is the replay itself; the DB-backed write path around
 * it can't run here (no pdo_sqlite), so its rule is pinned down in isolation.
 */
class PortfolioTransactionReplayTest extends TestCase
{
    private static function trade(TransactionType $type, string $quantity): array
    {
        return ['type' => $type, 'quantity' => $quantity];
    }

    public function test_net_quantity_is_buys_minus_sells(): void
    {
        $quantity = PortfolioTransactionService::replay([
            self::trade(TransactionType::Buy, '1.5'),
            self::trade(TransactionType::Sell, '0.4'),
            self::trade(TransactionType::Buy, '0.25'),
        ]);

        $this->assertSame(1.35, $quantity);
    }

    public function test_selling_everything_lands_exactly_on_zero_despite_float_noise(): void
    {
        $quantity = PortfolioTransactionService::replay([
            self::trade(TransactionType::Buy, '0.1'),
            self::trade(TransactionType::Buy, '0.2'),
            self::trade(TransactionType::Sell, '0.3'),
        ]);

        $this->assertSame(0.0, $quantity);
    }

    public function test_a_sell_before_the_matching_buy_is_rejected_even_if_the_total_balances(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('1');

        PortfolioTransactionService::replay([
            self::trade(TransactionType::Buy, '1'),
            self::trade(TransactionType::Sell, '2'),
            self::trade(TransactionType::Buy, '5'),
        ]);
    }

    public function test_an_empty_journal_holds_nothing(): void
    {
        $this->assertSame(0.0, PortfolioTransactionService::replay([]));
    }
}
