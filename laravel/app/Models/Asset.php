<?php

namespace App\Models;

use App\Enums\AssetType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    use Auditable;

    protected $fillable = [
        'name',
        'symbol',
        'type',
        'logo_url',
        'external_id',
        'last_known_price_usd',
        'last_price_synced_at',
    ];

    protected $casts = [
        'type' => AssetType::class,
        'last_known_price_usd' => 'decimal:8',
        'last_price_synced_at' => 'datetime',
    ];

    public function portfolioEntries(): HasMany
    {
        return $this->hasMany(PortfolioEntry::class);
    }

    public function priceAlerts(): HasMany
    {
        return $this->hasMany(PriceAlert::class);
    }
}
