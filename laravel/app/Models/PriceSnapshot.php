<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'asset_id',
        'price_usd',
        'recorded_at',
    ];

    protected $casts = [
        'price_usd' => 'decimal:8',
        'recorded_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Latest known price for the asset at or before the given instant, or
     * null if we have no history that far back yet.
     */
    public static function priceAt(int $assetId, \DateTimeInterface $at): ?float
    {
        $price = static::where('asset_id', $assetId)
            ->where('recorded_at', '<=', $at)
            ->orderByDesc('recorded_at')
            ->value('price_usd');

        return $price !== null ? (float) $price : null;
    }
}
