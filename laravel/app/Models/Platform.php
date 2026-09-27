<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasLogo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Platform extends Model
{
    use Auditable, HasLogo;

    protected $fillable = [
        'name',
    ];

    public function portfolioEntries(): HasMany
    {
        return $this->hasMany(PortfolioEntry::class);
    }
}
