<?php

namespace App\Notifications\Concerns;

use App\Enums\Currency;

trait FormatsNumbersForHumans
{
    /**
     * French convention: space for thousands, comma for decimals — e.g.
     * 100000000.6789 → "100 000 000,6789". Trims insignificant trailing
     * zeros rather than forcing a fixed decimal count, so a round price
     * doesn't get a padded ",00" and a precise one doesn't get truncated.
     * No i18n elsewhere in the app yet, but this keeps the formatting rule
     * in one place rather than repeating number_format() calls everywhere.
     */
    protected function formatNumber(float|string $value, int $maxDecimals = 2): string
    {
        $formatted = number_format((float) $value, $maxDecimals, ',', ' ');

        if (str_contains($formatted, ',')) {
            $formatted = rtrim(rtrim($formatted, '0'), ',');
        }

        return $formatted;
    }

    /**
     * An amount in French style with its symbol after it. Always 2 decimals
     * (money reads oddly as "80 357,4 €"), except below 1 where small
     * crypto prices keep up to 8 so they don't round to 0.
     */
    protected function formatMoney(float $amount, Currency $currency): string
    {
        $formatted = abs($amount) >= 1 || $amount == 0.0
            ? number_format($amount, 2, ',', ' ')
            : $this->formatNumber($amount, 8);

        return $formatted.' '.match ($currency) {
            Currency::Usd => '$',
            Currency::Eur => '€',
            Currency::Gbp => '£',
            Currency::Chf => 'CHF',
        };
    }

    /** "+1 234,50 €" / "-80,00 €": explicit sign for gains and losses. */
    protected function formatSignedMoney(float $amount, Currency $currency): string
    {
        return ($amount > 0 ? '+' : '').$this->formatMoney($amount, $currency);
    }

    /** "+4,25 %" / "-6,42 %". */
    protected function formatSignedPercent(float $percent, int $maxDecimals = 2): string
    {
        return ($percent > 0 ? '+' : '').$this->formatNumber($percent, $maxDecimals).' %';
    }
}
