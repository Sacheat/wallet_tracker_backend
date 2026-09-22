<?php

namespace App\Enums;

enum Currency: string
{
    case Usd = 'USD';
    case Eur = 'EUR';
    case Gbp = 'GBP';
    case Chf = 'CHF';
}
