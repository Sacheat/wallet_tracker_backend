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

    protected $casts = [
        'created_by' => 'integer',
    ];

    // See Asset: server-managed, only feeds CatalogPolicy.
    protected $hidden = ['created_by'];

    public function portfolioEntries(): HasMany
    {
        return $this->hasMany(PortfolioEntry::class);
    }

    public function isUsedByOtherUserThan(User $user): bool
    {
        return $this->portfolioEntries()->where('user_id', '!=', $user->id)->exists();
    }
}
