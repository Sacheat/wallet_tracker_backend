<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Models\PortfolioTransaction;
use Illuminate\Support\Collection;

/**
 * Profit and loss of a position, by weighted average cost (PMP, the
 * method French tax rules use for securities): each buy blends into one
 * average price, a sell realizes the gap between its price and that
 * average and leaves the average unchanged. Fees count against the
 * investor: added to the cost of a buy, taken off the proceeds of a sell.
 *
 * Computed separately in every supported currency, each trade converted
 * at the exchange rate of its own date (stored on the transaction), so a
 * EUR investor's figure includes the currency effect a USD-only figure
 * would hide. Current value is converted at today's rate.
 */
class PerformanceCalculator
{
    /**
     * Keyed by currency code; null when any trade lacks a price (profit unknown).
     *
     * @param  iterable<PortfolioTransaction>  $transactions  Chronological order.
     * @param  array<string, float|null>  $currentRates  Today's USD→currency rates, USD excluded.
     * @return array<string, array{cost_basis: float, average_buy_price: float|null, unrealized_pnl: float, unrealized_pnl_percent: float|null, realized_pnl: float}>|null
     */
    public function forPosition(iterable $transactions, float $valueUsd, array $currentRates): ?array
    {
        $transactions = collect($transactions);
        if ($transactions->contains(fn (PortfolioTransaction $t) => $t->unit_price === null || $t->fx_rates === null)) {
            return null;
        }

        $performance = [];
        foreach (Currency::cases() as $currency) {
            $currentRate = $currency === Currency::Usd ? 1.0 : ($currentRates[$currency->value] ?? null);
            if ($currentRate === null) {
                continue;
            }

            $performance[$currency->value] = $this->inCurrency($transactions, $currency, $valueUsd * $currentRate);
        }

        return $performance;
    }

    /**
     * What the quantity still held cost, per currency, at each trade's own
     * exchange rate: the "invested" line of the history chart.
     *
     * @param  iterable<PortfolioTransaction>  $transactions  Chronological order.
     * @return array<string, float>|null Keyed by currency code; null when any trade lacks a price.
     */
    public function costBasis(iterable $transactions): ?array
    {
        $performance = $this->forPosition($transactions, 0.0, ['EUR' => 1.0, 'GBP' => 1.0, 'CHF' => 1.0]);

        return $performance === null ? null : array_map(fn (array $figures) => $figures['cost_basis'], $performance);
    }

    /**
     * Sums positions whose profit is known. Positions still missing a
     * price are left out, not counted as zero: the caller reports how many.
     *
     * @param  iterable<array<string, array<string, float|null>>|null>  $positions  forPosition() results.
     * @return array<string, array{cost_basis: float, unrealized_pnl: float, unrealized_pnl_percent: float|null, realized_pnl: float}>
     */
    public function total(iterable $positions): array
    {
        $totals = [];
        foreach ($positions as $position) {
            foreach ($position ?? [] as $currency => $figures) {
                $totals[$currency] ??= ['cost_basis' => 0.0, 'unrealized_pnl' => 0.0, 'realized_pnl' => 0.0];
                $totals[$currency]['cost_basis'] += $figures['cost_basis'];
                $totals[$currency]['unrealized_pnl'] += $figures['unrealized_pnl'];
                $totals[$currency]['realized_pnl'] += $figures['realized_pnl'];
            }
        }

        return array_map(fn (array $t) => [
            'cost_basis' => round($t['cost_basis'], 2),
            'unrealized_pnl' => round($t['unrealized_pnl'], 2),
            'unrealized_pnl_percent' => $this->percent($t['unrealized_pnl'], $t['cost_basis']),
            'realized_pnl' => round($t['realized_pnl'], 2),
        ], $totals);
    }

    /**
     * @param  Collection<int, PortfolioTransaction>  $transactions
     * @return array{cost_basis: float, average_buy_price: float|null, unrealized_pnl: float, unrealized_pnl_percent: float|null, realized_pnl: float}
     */
    private function inCurrency($transactions, Currency $target, float $currentValue): array
    {
        $held = 0.0;
        $cost = 0.0;
        $realized = 0.0;

        foreach ($transactions as $transaction) {
            $toTarget = $this->conversionFactor($transaction, $target);
            $quantity = (float) $transaction->quantity;
            $price = (float) $transaction->unit_price * $toTarget;
            $fee = (float) $transaction->fee * $toTarget;

            if ($transaction->type === TransactionType::Buy) {
                $held += $quantity;
                $cost += $quantity * $price + $fee;

                continue;
            }

            $averageCost = $held > 0 ? $cost / $held : 0.0;
            $realized += $quantity * ($price - $averageCost) - $fee;
            $cost -= $quantity * $averageCost;
            $held -= $quantity;
        }

        // A fully sold position leaves float dust; nothing is held anymore.
        if ($held < 1e-9) {
            $held = 0.0;
            $cost = 0.0;
        }

        $unrealized = $currentValue - $cost;

        return [
            'cost_basis' => round($cost, 2),
            'average_buy_price' => $held > 0 ? round($cost / $held, 8) : null,
            'unrealized_pnl' => round($unrealized, 2),
            'unrealized_pnl_percent' => $this->percent($unrealized, $cost),
            'realized_pnl' => round($realized, 2),
        ];
    }

    /**
     * Rates are stored as USD→X on the trade date, so any currency pair
     * goes through USD: amount / rate(from) * rate(to), with USD = 1.
     */
    private function conversionFactor(PortfolioTransaction $transaction, Currency $target): float
    {
        $rates = [Currency::Usd->value => 1.0, ...$transaction->fx_rates];

        return $rates[$target->value] / $rates[$transaction->currency->value];
    }

    private function percent(float $gain, float $base): ?float
    {
        return $base > 0 ? round($gain / $base * 100, 2) : null;
    }
}
