<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortfolioEntryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'asset' => $this->asset->name,
            'asset_type' => $this->asset->type->value,
            'platform' => $this->platform->name,
            'quantity' => $this->quantity,
            'value_usd' => $this->value_usd,
            'value_eur' => $this->value_eur,
        ];
    }
}
