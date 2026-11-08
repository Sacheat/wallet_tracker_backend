<?php

namespace App\DTO;

use Illuminate\Support\Collection;

final readonly class PortfolioSummary
{
    /**
     * @param  array<string, float|null>  $changePercent  Keyed by AlertWindow->value ('1h', '24h', '7d').
     * @param  array<string, float|null>  $totalValueByCurrency  Keyed by ISO currency code, USD included.
     * @param  array<string, array<string, float|null>>  $performance  PerformanceCalculator::total(), keyed by currency.
     * @param  int  $positionsWithoutPrice  Positions left out of $performance until their trades all have a price.
     */
    public function __construct(
        public Collection $entries,
        public float $totalValueUsd,
        public float $totalValueEur,
        public array $changePercent,
        public array $totalValueByCurrency,
        public array $performance = [],
        public int $positionsWithoutPrice = 0,
    ) {}
}
