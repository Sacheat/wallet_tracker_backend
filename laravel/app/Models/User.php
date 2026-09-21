<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'name',
    'email',
    'password',
    'email_notifications_enabled',
    'discord_webhook_url',
    'daily_digest_enabled',
    'weekly_digest_enabled',
])]
// avatar_path is deliberately excluded from Fillable: it's only ever set by
// AvatarController via a direct attribute assignment, never through mass
// assignment on the general profile-update endpoint.
#[Hidden(['password', 'remember_token', 'avatar_path'])]
#[Appends(['avatar_url'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'email_notifications_enabled' => 'boolean',
            'daily_digest_enabled' => 'boolean',
            'weekly_digest_enabled' => 'boolean',
        ];
    }

    protected function avatarUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->avatar_path ? Storage::disk('public')->url($this->avatar_path) : null,
        );
    }

    public function portfolioEntries(): HasMany
    {
        return $this->hasMany(PortfolioEntry::class);
    }

    public function priceAlerts(): HasMany
    {
        return $this->hasMany(PriceAlert::class);
    }

    /**
     * Where the custom Discord channel should deliver notifications.
     */
    public function routeNotificationForDiscord(): ?string
    {
        return $this->discord_webhook_url;
    }
}
