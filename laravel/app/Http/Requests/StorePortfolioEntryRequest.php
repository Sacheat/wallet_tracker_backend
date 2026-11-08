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
     * The position's opening buy. Its price fields are optional so the
     * original { asset_id, platform_id, quantity } payload keeps working.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $price = StorePortfolioTransactionRequest::priceRules();

        return [
            'asset_id' => 'required|integer|exists:assets,id',
            'platform_id' => [
                'required', 'integer', 'exists:platforms,id',
                Rule::unique('portfolio_entries')
                    ->where('user_id', $this->user()->id)
                    ->where('asset_id', $this->input('asset_id')),
            ],
            // Column is decimal(20,8): 12 integer digits. Past that, MySQL
            // rejects the write and the client got a bare 500.
            'quantity' => 'required|numeric|gt:0|max:999999999999',
            'unit_price' => ['nullable', ...$price['unit_price']],
            'fee' => $price['fee'],
            'currency' => $price['currency'],
            'executed_at' => ['nullable', ...$price['executed_at']],
        ];
    }
}
