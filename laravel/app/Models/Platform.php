<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Platform extends Model
{
    use Auditable;

    protected $fillable = [
        'name',
        'logo_url',
    ];

    public function portfolioEntries(): HasMany
    {
        return $this->hasMany(PortfolioEntry::class);
    }
}
