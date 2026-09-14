<?php

namespace App\Models;

use App\Enums\AlertType;
use App\Enums\AlertWindow;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceAlert extends Model
{
    use Auditable;

    protected $fillable = [
        'user_id',
        'asset_id',
        'type',
        'threshold',
        'percent_threshold',
        'window',
        'is_active',
    ];

    protected $casts = [
        'type' => AlertType::class,
        'window' => AlertWindow::class,
        'threshold' => 'decimal:8',
        'percent_threshold' => 'decimal:2',
        'is_active' => 'boolean',
        'condition_met' => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
