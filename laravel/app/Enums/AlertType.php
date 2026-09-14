<?php

namespace App\Enums;

enum AlertType: string
{
    case PriceAbove = 'price_above';
    case PriceBelow = 'price_below';
    case PercentChange = 'percent_change';
    case PortfolioTotalAbove = 'portfolio_total_above';
    case PortfolioTotalBelow = 'portfolio_total_below';

    public function isAssetAlert(): bool
    {
        return in_array($this, [self::PriceAbove, self::PriceBelow, self::PercentChange]);
    }

    public function isPortfolioAlert(): bool
    {
        return in_array($this, [self::PortfolioTotalAbove, self::PortfolioTotalBelow]);
    }
}
