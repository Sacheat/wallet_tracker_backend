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
        'created_by' => 'integer',
    ];

    // created_by is set server-side (never fillable) and kept out of the
    // JSON: it only feeds CatalogPolicy, other users don't need to know who
    // added an entry.
    protected $hidden = ['created_by'];

    public function portfolioEntries(): HasMany
    {
        return $this->hasMany(PortfolioEntry::class);
    }

    public function priceAlerts(): HasMany
    {
        return $this->hasMany(PriceAlert::class);
    }

    public function isUsedByOtherUserThan(User $user): bool
    {
        return $this->portfolioEntries()->where('user_id', '!=', $user->id)->exists()
            || $this->priceAlerts()->where('user_id', '!=', $user->id)->exists();
    }
}
