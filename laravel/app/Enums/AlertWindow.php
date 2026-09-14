<?php

namespace App\Enums;

enum AlertWindow: string
{
    case OneHour = '1h';
    case TwentyFourHours = '24h';
    case SevenDays = '7d';

    /**
     * How far back to look for a comparison price, and — reused as the
     * cooldown — how long to wait before this alert can fire again.
     */
    public function hours(): int
    {
        return match ($this) {
            self::OneHour => 1,
            self::TwentyFourHours => 24,
            self::SevenDays => 24 * 7,
        };
    }
}
