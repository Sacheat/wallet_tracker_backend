<?php

namespace App\Services;

use App\DTO\PortfolioSummary;
use App\Enums\AssetType;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Where the portfolio's value sits (by asset type, platform and asset),
 * against the user's target share per asset type, with two ways back to
 * the target: a full rebalance (buy and sell), or spreading new money over
 * the underweight types only (no sale, so no taxable gain and no selling
 * fees, which is how most individual investors actually rebalance).
 *
 * Amounts are in USD like the rest of the API's stored values; converting
 * at today's rate on the client is exact here (nothing historical).
 */
class AllocationService
{
    public function __construct(private PortfolioService $portfolioService) {}

    /**
     * @param  float|null  $contributionUsd  New money to spread without selling, already in USD.
     * @return array<string, mixed>
     */
    public function forUser(User $user, ?float $contributionUsd = null): array
    {
        return $this->build($this->portfolioService->getPortfolioSummary($user), $user->allocation_targets ?? [], $contributionUsd);
    }

    /**
     * Largest gap, in percentage points, between an asset type's current
     * share and its target. Null without targets or with an empty portfolio.
     */
    public function maxDrift(PortfolioSummary $summary, array $targets): ?float
    {
        return $this->build($summary, $targets, null)['max_drift'];
    }

    /**
     * @param  array<string, float|int>  $targets  Percent per AssetType value, adding up to 100; [] for none.
     * @return array<string, mixed>
     */
    public function build(PortfolioSummary $summary, array $targets, ?float $contributionUsd): array
    {
        $entries = $summary->entries;
        $total = (float) $entries->sum('value_usd');
        $hasTargets = $targets !== [];

        $valueByType = [];
        foreach (AssetType::cases() as $type) {
            $value = (float) $entries->filter(fn ($entry) => $entry->asset->type === $type)->sum('value_usd');
            $target = $targets[$type->value] ?? 0;
            if ($value > 0 || $target > 0) {
                $valueByType[$type->value] = $value;
            }
        }
        $targetShares = $hasTargets
            ? array_map(fn (string $type) => ($targets[$type] ?? 0) / 100, array_combine(array_keys($valueByType), array_keys($valueByType)))
            : [];

        $rebalance = $hasTargets ? self::rebalance($valueByType, $targetShares) : [];
        $contribution = $hasTargets && $contributionUsd !== null ? self::spreadContribution($valueByType, $targetShares, $contributionUsd) : [];

        $byType = collect($valueByType)->map(function (float $value, string $type) use ($total, $hasTargets, $targets, $rebalance, $contribution) {
            $percent = $this->share($value, $total);
            $target = $hasTargets ? (float) ($targets[$type] ?? 0) : null;

            return [
                'type' => $type,
                'value_usd' => round($value, 2),
                'percent' => $percent,
                'target_percent' => $target,
                'drift' => $target !== null && $percent !== null ? round($percent - $target, 2) : null,
                'rebalance_usd' => isset($rebalance[$type]) ? round($rebalance[$type], 2) : null,
                'contribution_usd' => isset($contribution[$type]) ? round($contribution[$type], 2) : null,
            ];
        })->sortByDesc('value_usd')->values();

        $drifts = $byType->pluck('drift')->filter(fn ($drift) => $drift !== null);

        return [
            'total_value_usd' => round($total, 2),
            'has_targets' => $hasTargets,
            'max_drift' => $total > 0 && $drifts->isNotEmpty() ? round($drifts->map(fn ($drift) => abs($drift))->max(), 2) : null,
            'by_type' => $byType->all(),
            'by_platform' => $this->group($entries, fn ($entry) => ['platform' => $entry->platform->name], $total),
            'by_asset' => $this->group($entries, fn ($entry) => ['asset' => $entry->asset->name, 'asset_type' => $entry->asset->type->value], $total),
        ];
    }

    /**
     * Amount to buy (positive) or sell (negative) per type to land exactly
     * on target, the total staying the same.
     *
     * @param  array<string, float>  $values  USD per type.
     * @param  array<string, float>  $targetShares  0..1 per type, same keys.
     * @return array<string, float>
     */
    public static function rebalance(array $values, array $targetShares): array
    {
        $total = array_sum($values);

        return array_map(fn (string $type) => $total * $targetShares[$type] - $values[$type], array_combine(array_keys($values), array_keys($values)));
    }

    /**
     * Spreads new money over underweight types only, in proportion to how
     * far below target each one would be once the money is in. Nothing is
     * sold, so an overweight type is never fully corrected, only diluted.
     * (The deficits always add up to at least the contribution, since all
     * gaps to target sum to exactly the money added.)
     *
     * @param  array<string, float>  $values  USD per type.
     * @param  array<string, float>  $targetShares  0..1 per type, same keys.
     * @return array<string, float>
     */
    public static function spreadContribution(array $values, array $targetShares, float $contribution): array
    {
        $newTotal = array_sum($values) + $contribution;
        $deficits = [];
        foreach ($values as $type => $value) {
            $deficits[$type] = max(0.0, $newTotal * $targetShares[$type] - $value);
        }

        $totalDeficit = array_sum($deficits);

        return array_map(
            fn (float $deficit) => $totalDeficit > 0 ? $contribution * $deficit / $totalDeficit : 0.0,
            $deficits,
        );
    }

    /**
     * @param  Collection<int, mixed>  $entries
     * @param  callable(mixed): array<string, string>  $key  Fields identifying the group.
     * @return array<int, array<string, mixed>>
     */
    private function group(Collection $entries, callable $key, float $total): array
    {
        return $entries
            ->groupBy(fn ($entry) => json_encode($key($entry)))
            ->map(function (Collection $group) use ($key, $total) {
                $value = (float) $group->sum('value_usd');

                return [...$key($group->first()), 'value_usd' => round($value, 2), 'percent' => $this->share($value, $total)];
            })
            ->filter(fn (array $row) => $row['value_usd'] > 0)
            ->sortByDesc('value_usd')
            ->values()
            ->all();
    }

    private function share(float $value, float $total): ?float
    {
        return $total > 0 ? round($value / $total * 100, 2) : null;
    }
}
