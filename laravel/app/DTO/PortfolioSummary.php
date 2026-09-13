<?php

namespace App\DTO;

use Illuminate\Support\Collection;

final readonly class PortfolioSummary
{
    public function __construct(
        public Collection $entries,
        public float $totalValueUsd,
        public float $totalValueEur,
    ) {}
}
