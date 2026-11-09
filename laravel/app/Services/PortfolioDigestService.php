<?php

namespace App\Services;

use App\DTO\PortfolioSummary;
use App\Enums\AlertWindow;
use App\Enums\Currency;
use App\Models\User;
use RuntimeException;

/**
 * The figures behind a digest, computed once when it is dispatched and
 * handed to the notification as plain data (no models to re-fetch in the
 * queue, and the mail and Discord jobs show the exact same numbers).
 *
 * Everything is in the user's currency: the total and the P&L come
 * already converted from PortfolioService (P&L at each trade's own rate),
 * position values and moves are converted at today's rate. Positions sold
 * down to zero are left out: they are history, not holdings.
 */
class PortfolioDigestService
{
    /** How many positions the email lists before "and N more". */
    public const POSITIONS_SHOWN = 10;

    /** How many risers and fallers each. */
    public const MOVERS_SHOWN = 3;

    public function __construct(
        private PriceService $priceService,
        private PriceChangeCalculator $priceChangeCalculator,
        private AllocationService $allocationService,
    ) {}

    /**
     * @param  'daily'|'weekly'  $frequency
     * @return array{
     *     currency: string,
     *     window: string,
     *     total: float,
     *     change: array<string, array{percent: float, amount: float}|null>,
     *     performance: array{unrealized_pnl: float, unrealized_pnl_percent: float|null, realized_pnl: float}|null,
     *     positions_without_price: int,
     *     risers: array<int, array{name: string, symbol: string, percent: float}>,
     *     fallers: array<int, array{name: string, symbol: string, percent: float}>,
     *     allocation: array{has_targets: bool, max_drift: float|null, rows: array<int, array{type: string, percent: float|null, target_percent: float|null}>},
     *     positions: array<int, array{name: string, symbol: string, platform: string, quantity: float, value: float, share: float|null, pnl_percent: float|null}>,
     *     positions_count: int
     * }|null Null when nothing is held (nothing to report).
     */
    public function build(User $user, PortfolioSummary $summary, string $frequency): ?array
    {
        $held = $summary->entries->filter(fn ($entry) => (float) $entry->quantity > 0)->values();
        if ($held->isEmpty()) {
            return null;
        }

        [$currency, $rate] = $this->displayCurrency($user);
        $total = $currency === Currency::Usd ? $summary->totalValueUsd : (float) ($summary->totalValueByCurrency[$currency->value] ?? $summary->totalValueUsd * $rate);
        $window = $frequency === 'daily' ? AlertWindow::TwentyFourHours : AlertWindow::SevenDays;

        return [
            'currency' => $currency->value,
            'window' => $window->value,
            'total' => round($total, 2),
            'change' => collect([AlertWindow::TwentyFourHours, AlertWindow::SevenDays])
                ->mapWithKeys(fn (AlertWindow $w) => [$w->value => $this->change($summary->changePercent[$w->value] ?? null, $total)])
                ->all(),
            'performance' => isset($summary->performance[$currency->value]) ? [
                'unrealized_pnl' => (float) $summary->performance[$currency->value]['unrealized_pnl'],
                'unrealized_pnl_percent' => $summary->performance[$currency->value]['unrealized_pnl_percent'],
                'realized_pnl' => (float) $summary->performance[$currency->value]['realized_pnl'],
            ] : null,
            'positions_without_price' => $summary->positionsWithoutPrice,
            ...$this->movers($held, $window),
            'allocation' => $this->allocation($user, $summary),
            'positions' => $held->take(self::POSITIONS_SHOWN)->map(fn ($entry) => [
                'name' => $entry->asset->name,
                'symbol' => $entry->asset->symbol,
                'platform' => $entry->platform->name,
                'quantity' => (float) $entry->quantity,
                'value' => round($entry->value_usd * $rate, 2),
                'share' => $summary->totalValueUsd > 0 ? round($entry->value_usd / $summary->totalValueUsd * 100, 2) : null,
                'pnl_percent' => $entry->performance[$currency->value]['unrealized_pnl_percent'] ?? null,
            ])->all(),
            'positions_count' => $held->count(),
        ];
    }

    /**
     * The amount a percent change stands for: the value at the start of
     * the window is total / (1 + p), so the move is the difference.
     *
     * @return array{percent: float, amount: float}|null
     */
    private function change(?float $percent, float $total): ?array
    {
        if ($percent === null || $percent <= -100.0) {
            return null;
        }

        return ['percent' => round($percent, 2), 'amount' => round($total - $total / (1 + $percent / 100), 2)];
    }

    /**
     * Per asset (an asset held on two platforms counts once), over the
     * digest's own window: 24h for the daily one, 7 days for the weekly.
     *
     * @return array{risers: array<int, array{name: string, symbol: string, percent: float}>, fallers: array<int, array{name: string, symbol: string, percent: float}>}
     */
    private function movers($held, AlertWindow $window): array
    {
        $moves = $held->groupBy('asset_id')->map(function ($entries) use ($window) {
            $asset = $entries->first()->asset;
            $quantity = (float) $entries->sum('quantity');
            $valueUsd = (float) $entries->sum('value_usd');
            $percent = $quantity > 0 && $valueUsd > 0
                ? $this->priceChangeCalculator->percentChange($asset, $window, $valueUsd / $quantity)
                : null;

            return $percent === null ? null : ['name' => $asset->name, 'symbol' => $asset->symbol, 'percent' => round($percent, 2)];
        })->filter()->values();

        return [
            'risers' => $moves->filter(fn (array $move) => $move['percent'] > 0)->sortByDesc('percent')->take(self::MOVERS_SHOWN)->values()->all(),
            'fallers' => $moves->filter(fn (array $move) => $move['percent'] < 0)->sortBy('percent')->take(self::MOVERS_SHOWN)->values()->all(),
        ];
    }

    /**
     * @return array{has_targets: bool, max_drift: float|null, rows: array<int, array{type: string, percent: float|null, target_percent: float|null}>}
     */
    private function allocation(User $user, PortfolioSummary $summary): array
    {
        $allocation = $this->allocationService->build($summary, $user->allocation_targets ?? [], null);

        return [
            'has_targets' => $allocation['has_targets'],
            'max_drift' => $allocation['max_drift'],
            'rows' => collect($allocation['by_type'])
                ->map(fn (array $row) => ['type' => $row['type'], 'percent' => $row['percent'], 'target_percent' => $row['target_percent']])
                ->all(),
        ];
    }

    /**
     * @return array{0: Currency, 1: float} The user's currency and its USD rate; USD when no rate can be had.
     */
    private function displayCurrency(User $user): array
    {
        $currency = $user->currency instanceof Currency ? $user->currency : Currency::Usd;
        if ($currency === Currency::Usd) {
            return [Currency::Usd, 1.0];
        }

        try {
            $rate = $this->priceService->getUsdToCurrencyRates([$currency->value])[$currency->value];
        } catch (RuntimeException) {
            $rate = null;
        }

        return $rate === null ? [Currency::Usd, 1.0] : [$currency, (float) $rate];
    }
}
