<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
