<?php

namespace App\Notifications\Concerns;

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
}
