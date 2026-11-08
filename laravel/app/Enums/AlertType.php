<?php

namespace App\Enums;

enum AlertType: string
{
    case PriceAbove = 'price_above';
    case PriceBelow = 'price_below';
    case PercentChange = 'percent_change';
    case PortfolioTotalAbove = 'portfolio_total_above';
    case PortfolioTotalBelow = 'portfolio_total_below';
    // Fires when an asset type's share of the portfolio strays from the
    // user's allocation target by percent_threshold points or more.
    case AllocationDrift = 'allocation_drift';

    public function isAssetAlert(): bool
    {
        return in_array($this, [self::PriceAbove, self::PriceBelow, self::PercentChange]);
    }

    public function isPortfolioAlert(): bool
    {
        return in_array($this, [self::PortfolioTotalAbove, self::PortfolioTotalBelow]);
    }
}
