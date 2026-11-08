<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortfolioTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'portfolio_entry_id' => $this->portfolio_entry_id,
            'type' => $this->type->value,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'fee' => $this->fee,
            'currency' => $this->currency->value,
            'executed_at' => $this->executed_at->toIso8601String(),
            // Returned so the client can refresh the position without a
            // second request: every write recomputes it.
            'position_quantity' => $this->portfolioEntry->quantity,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
