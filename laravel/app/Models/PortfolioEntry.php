<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioEntry extends Model
{
    protected $fillable = [
        'crypto_id',
        'platform_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:8',
    ];

    public function crypto(): BelongsTo
    {
        return $this->belongsTo(Crypto::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }
}
