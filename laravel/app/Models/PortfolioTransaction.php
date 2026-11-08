<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioTransaction extends Model
{
    use Auditable;

    // portfolio_entry_id is set through $entry->transactions()->create(),
    // fx_rates only by PortfolioTransactionService: neither comes from
    // request data.
    protected $fillable = [
        'type',
        'quantity',
        'unit_price',
        'fee',
        'currency',
        'executed_at',
    ];

    protected $hidden = ['fx_rates'];

    protected $casts = [
        'type' => TransactionType::class,
        'currency' => Currency::class,
        'quantity' => 'decimal:8',
        'unit_price' => 'decimal:10',
        'fee' => 'decimal:8',
        'fx_rates' => 'array',
        'executed_at' => 'datetime',
    ];

    public function portfolioEntry(): BelongsTo
    {
        return $this->belongsTo(PortfolioEntry::class);
    }

    /**
     * Auditable falls back to the record's user_id when no one is logged in
     * (console); a transaction has none of its own, so lend it the owner's.
     */
    public function getUserIdAttribute(): ?int
    {
        return $this->portfolioEntry?->user_id;
    }
}
