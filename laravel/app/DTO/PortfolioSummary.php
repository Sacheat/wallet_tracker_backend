<?php

namespace App\DTO;

use Illuminate\Support\Collection;

final readonly class PortfolioSummary
{
    /**
     * @param  array<string, float|null>  $changePercent  Keyed by AlertWindow->value ('1h', '24h', '7d').
     * @param  array<string, float|null>  $totalValueByCurrency  Keyed by ISO currency code, USD included.
     */
    public function __construct(
        public Collection $entries,
        public float $totalValueUsd,
        public float $totalValueEur,
        public array $changePercent,
        public array $totalValueByCurrency,
    ) {}
}
