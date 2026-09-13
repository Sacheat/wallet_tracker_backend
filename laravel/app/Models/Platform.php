<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Platform extends Model
{
    protected $fillable = [
        'name',
        'logo_url',
    ];

    public function portfolioEntries() :HasMany {
        return $this->hasMany(PortfolioEntry::class);
    }
}
