<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortfolioEntry extends Model
{
    use Auditable;

    // user_id is deliberately not fillable: it is set from the authenticated
    // user through $user->portfolioEntries()->create(), never from request data.
    protected $fillable = [
        'asset_id',
        'platform_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    /**
     * Buy/sell journal; `quantity` above is its cached net total, kept in
     * sync by PortfolioTransactionService.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PortfolioTransaction::class);
    }
}
