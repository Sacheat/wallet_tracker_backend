<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function crypto()
    {
        return $this->belongsTo(Crypto::class);
    }

    public function platform()
    {
        return $this->belongsTo(Platform::class);
    }
}
