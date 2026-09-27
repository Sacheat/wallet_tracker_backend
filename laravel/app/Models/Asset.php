<?php

namespace App\Models;

use App\Enums\AssetType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasLogo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    use Auditable, HasLogo;

    protected $fillable = [
        'name',
        'symbol',
        'type',
        'external_id',
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
