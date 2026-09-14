<?php

namespace App\Enums;

enum AssetType: string
{
    case Crypto = 'crypto';
    case Stock = 'stock';
    case Gold = 'gold';
    case Etf = 'etf';
}
