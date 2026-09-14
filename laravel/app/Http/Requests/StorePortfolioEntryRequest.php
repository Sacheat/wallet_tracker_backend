<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePortfolioEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'asset_id' => 'required|integer|exists:assets,id',
            'platform_id' => [
                'required', 'integer', 'exists:platforms,id',
                Rule::unique('portfolio_entries')
                    ->where('user_id', $this->user()->id)
                    ->where('asset_id', $this->input('asset_id')),
            ],
            'quantity' => 'required|numeric|gt:0',
        ];
    }
}
