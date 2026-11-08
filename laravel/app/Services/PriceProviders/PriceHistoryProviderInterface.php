<?php

namespace App\Services\PriceProviders;

/**
 * Daily closing prices over the last year, for rebuilding a portfolio's
 * value on past days. One year because that is all CoinGecko's free tier
 * serves (a 400-day request answers 401, checked live 2026-11-08).
 */
interface PriceHistoryProviderInterface
{
    public const HISTORY_DAYS = 365;

    /**
     * @return array<string, float> USD close keyed by UTC date (Y-m-d), oldest first;
     *                              days the market was closed are simply absent. Empty when unavailable.
     */
    public function getDailyUsdCloses(string $externalId): array;
}
