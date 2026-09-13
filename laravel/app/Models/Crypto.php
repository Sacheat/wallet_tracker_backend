<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Crypto extends Model
{
    protected $fillable = [
        'name',
        'symbol',
        'logo_url',
        'coingecko_id',
        'last_known_price_usd',
        'last_price_synced_at'
    ];

    protected $casts = [
        'last_known_price_usd' => 'decimal:8',
        'last_price_synced_at' => 'datetime'
    ];

    public function portfolioEntries(): HasMany
    {
        return $this->hasMany(PortfolioEntry::class);
    }
}
