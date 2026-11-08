<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Models\Asset;
use App\Models\PriceAlert;
use App\Models\PriceSnapshot;
use App\Notifications\PriceAlertTriggered;

class AlertService
{
    public function __construct(
        private PriceService $priceService,
        private PortfolioService $portfolioService,
        private PriceChangeCalculator $priceChangeCalculator,
        private AllocationService $allocationService,
    ) {}

    /**
     * Snapshot the current USD price of every asset that's either held by
     * someone or watched by an active alert, so percent-change alerts have
     * history to compare against.
     */
    public function recordSnapshots(): void
    {
        $assets = Asset::whereHas('portfolioEntries')
            ->orWhereHas('priceAlerts', fn ($query) => $query->where('is_active', true))
            ->get();

        if ($assets->isEmpty()) {
            return;
        }

        $prices = $this->priceService->getUsdPrices($assets);
        $now = now();

        foreach ($assets as $asset) {
            $price = $prices[$asset->external_id] ?? null;

            if ($price === null) {
                continue;
            }

            PriceSnapshot::create([
                'asset_id' => $asset->id,
                'price_usd' => $price,
                'recorded_at' => $now,
            ]);
        }
    }

    public function checkAlerts(): void
    {
        $this->checkAssetAlerts();
        $this->checkPortfolioAlerts();
        $this->checkAllocationAlerts();
    }

    private function checkAssetAlerts(): void
    {
        $alerts = PriceAlert::query()
            ->with(['asset', 'user'])
            ->where('is_active', true)
            ->whereIn('type', [AlertType::PriceAbove, AlertType::PriceBelow, AlertType::PercentChange])
            ->get();

        if ($alerts->isEmpty()) {
            return;
        }

        $assets = $alerts->pluck('asset')->unique('id');
        $prices = $this->priceService->getUsdPrices($assets);

        foreach ($alerts as $alert) {
            $price = $prices[$alert->asset->external_id] ?? null;

            if ($price === null) {
                continue;
            }

            match ($alert->type) {
                AlertType::PriceAbove, AlertType::PriceBelow => $this->evaluateThreshold($alert, $price),
                AlertType::PercentChange => $this->evaluatePercentChange($alert, $price),
                default => null,
            };
        }
    }

    private function checkPortfolioAlerts(): void
    {
        $alerts = PriceAlert::query()
            ->with('user')
            ->where('is_active', true)
            ->whereIn('type', [AlertType::PortfolioTotalAbove, AlertType::PortfolioTotalBelow])
            ->get()
            ->groupBy('user_id');

        foreach ($alerts as $userAlerts) {
            $summary = $this->portfolioService->getPortfolioSummary($userAlerts->first()->user);

            foreach ($userAlerts as $alert) {
                $this->evaluateThreshold($alert, $summary->totalValueUsd);
            }
        }
    }

    /**
     * Same edge-triggering as the threshold alerts: one notification when
     * the largest gap to target reaches the alert's points, then silence
     * until the portfolio has come back within them. Targets removed since
     * (or an empty portfolio) simply never meet the condition.
     */
    private function checkAllocationAlerts(): void
    {
        $alerts = PriceAlert::query()
            ->with('user')
            ->where('is_active', true)
            ->where('type', AlertType::AllocationDrift)
            ->get()
            ->groupBy('user_id');

        foreach ($alerts as $userAlerts) {
            $user = $userAlerts->first()->user;
            $allocation = $this->allocationService->build(
                $this->portfolioService->getPortfolioSummary($user),
                $user->allocation_targets ?? [],
                null,
            );
            $worst = collect($allocation['by_type'])
                ->filter(fn (array $row) => $row['drift'] !== null)
                ->sortByDesc(fn (array $row) => abs($row['drift']))
                ->first();

            foreach ($userAlerts as $alert) {
                $conditionMet = $allocation['max_drift'] !== null && $allocation['max_drift'] >= (float) $alert->percent_threshold;

                if ($conditionMet && ! $alert->condition_met) {
                    $user->notify(new PriceAlertTriggered($alert, $allocation['max_drift'], context: [
                        'type' => $worst['type'],
                        'percent' => $worst['percent'],
                        'target_percent' => $worst['target_percent'],
                    ]));
                    $alert->last_triggered_at = now();
                }

                $alert->condition_met = $conditionMet;
                $alert->save();
            }
        }
    }

    /**
     * Edge-triggered: only notify the first time the condition becomes true,
     * not on every scheduler tick while it stays true.
     */
    private function evaluateThreshold(PriceAlert $alert, float $currentValue): void
    {
        $isAboveType = in_array($alert->type, [AlertType::PriceAbove, AlertType::PortfolioTotalAbove]);
        $conditionMet = $isAboveType
            ? $currentValue >= (float) $alert->threshold
            : $currentValue <= (float) $alert->threshold;

        if ($conditionMet && ! $alert->condition_met) {
            $alert->user->notify(new PriceAlertTriggered($alert, $currentValue));
            $alert->last_triggered_at = now();
        }

        $alert->condition_met = $conditionMet;
        $alert->save();
    }

    /**
     * Cooldown matches the alert's own window (a 24h-variation alert can't
     * refire more than once per 24h), since re-checking naturally handles
     * re-triggering as the rolling window moves.
     */
    private function evaluatePercentChange(PriceAlert $alert, float $currentPrice): void
    {
        $window = $alert->window;
        $percentChange = $this->priceChangeCalculator->percentChange($alert->asset, $window, $currentPrice);

        if ($percentChange === null) {
            return;
        }

        $cooldownElapsed = ! $alert->last_triggered_at
            || $alert->last_triggered_at->lt(now()->subHours($window->hours()));

        if (abs($percentChange) >= (float) $alert->percent_threshold && $cooldownElapsed) {
            $alert->user->notify(new PriceAlertTriggered($alert, $currentPrice, $percentChange));
            $alert->last_triggered_at = now();
            $alert->save();
        }
    }
}
