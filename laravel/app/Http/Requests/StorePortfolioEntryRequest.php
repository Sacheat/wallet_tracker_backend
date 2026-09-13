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
            'crypto_id' => 'required|integer|exists:cryptos,id',
            'platform_id' => [
                'required', 'integer', 'exists:platforms,id',
                Rule::unique('portfolio_entries')->where('crypto_id', $this->input('crypto_id')),
            ],
            'quantity' => 'required|numeric|gt:0',
        ];
    }
}